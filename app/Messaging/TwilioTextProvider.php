<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Core\Logger;
use App\Mail\SendResult;

/**
 * Twilio, for SMS and WhatsApp.
 *
 * Written against the REST API directly rather than the SDK, for the same
 * reason AmazonSesProvider is: one dependency fewer, and the request is four
 * fields. The vendor's name appears here and nowhere else.
 */
final class TwilioTextProvider implements TextProviderInterface
{
    /**
     * A starting point per country, in the organisation's currency. Real pricing
     * comes from the account; these exist so the "this will cost about £34"
     * warning has something to say before anybody has a bill to look at.
     *
     * @var array<string,float>
     */
    private const INDICATIVE_COST = [
        'US' => 0.0079,
        'AU' => 0.0515,
        'GB' => 0.0400,
        'NZ' => 0.0900,
        'CA' => 0.0079,
    ];

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $channelName = 'sms',
        private readonly ?Logger $logger = null,
    ) {
    }

    public function channel(): string
    {
        return $this->channelName;
    }

    public function name(): string
    {
        return 'twilio';
    }

    public function isConfigured(): bool
    {
        return (string) ($this->config['account_sid'] ?? '') !== ''
            && (string) ($this->config['auth_token'] ?? '') !== ''
            && function_exists('curl_init');
    }

    public function send(OutboundText $message): SendResult
    {
        if (!$this->isConfigured()) {
            return SendResult::rejected('Text messaging is not set up on this installation.');
        }

        $sid  = (string) $this->config['account_sid'];
        $from = $message->senderId ?? (string) ($this->config['from_number'] ?? '');

        if ($from === '') {
            return SendResult::rejected('No sending number is configured.');
        }

        // WhatsApp numbers are the same numbers with a scheme in front.
        if ($message->channel === 'whatsapp') {
            $from = str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:' . $from;
        }

        $to = $message->channel === 'whatsapp' ? 'whatsapp:' . $message->toNumber : $message->toNumber;

        $handle = curl_init(
            'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json'
        );

        if ($handle === false) {
            return SendResult::failed('Could not start a request to the text provider.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($this->config['timeout'] ?? 20),
            CURLOPT_USERPWD        => $sid . ':' . (string) $this->config['auth_token'],
            CURLOPT_POSTFIELDS     => http_build_query(array_filter([
                'To'   => $to,
                'From' => $from,
                'Body' => $message->body,
                'StatusCallback' => (string) ($this->config['status_callback'] ?? '') ?: null,
            ])),
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $error !== '') {
            return SendResult::failed('Could not reach the text provider: ' . $error);
        }

        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            return SendResult::accepted((string) ($decoded['sid'] ?? ''));
        }

        $reason = (string) ($decoded['message'] ?? 'HTTP ' . $status);

        $this->logger?->warning('Text provider refused a message', [
            'status' => $status,
            'code'   => $decoded['code'] ?? null,
        ]);

        // 4xx is our fault or the number's — retrying will not help, and each
        // retry of a text costs real money.
        return $status >= 400 && $status < 500
            ? SendResult::rejected($reason, (string) ($decoded['code'] ?? ''))
            : SendResult::failed($reason, (string) ($decoded['code'] ?? ''));
    }

    public function costPerMessage(string $countryCode): float
    {
        return self::INDICATIVE_COST[strtoupper($countryCode)] ?? 0.05;
    }

    public function maximumLength(): int
    {
        return 1600;
    }
}
