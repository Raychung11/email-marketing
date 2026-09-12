<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Logger;

/**
 * Amazon SES via the AWS SDK for PHP (SESv2).
 *
 * Requires `composer require aws/aws-sdk-php`. The SDK is a suggested rather than
 * a hard dependency so that the core application, its migrations and its test
 * suite install and run with no vendor tree at all; isConfigured() reports the
 * absence rather than the process dying at boot.
 *
 * Notes that matter in production:
 *  - ONE recipient per send. Never BCC, never multiple To.
 *  - Quotas differ per account and Region, and sandbox accounts are restricted to
 *    verified recipients at a low rate — so getQuota() is read at runtime and the
 *    worker throttles to it. Nothing here assumes a fixed production quota.
 *  - A configuration set is required for SES to publish open/click/bounce events
 *    to SNS.
 */
final class AmazonSesProvider implements EmailProviderInterface
{
    private ?object $client = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function channel(): string
    {
        return 'email';
    }

    public function name(): string
    {
        return 'ses';
    }

    public function isConfigured(): bool
    {
        if (!class_exists('\Aws\SesV2\SesV2Client')) {
            return false;
        }

        // Credentials may legitimately come from an instance role rather than
        // static keys, which is the preferred deployment.
        return (string) ($this->config['region'] ?? '') !== '';
    }

    public function send(OutboundMessage $message): SendResult
    {
        if (!$this->isConfigured()) {
            return SendResult::rejected(
                'Amazon SES is not configured. Install aws/aws-sdk-php and set AWS_REGION.',
                'SES_NOT_CONFIGURED'
            );
        }

        try {
            $request = [
                'FromEmailAddress' => $message->fromHeader(),
                'Destination'      => ['ToAddresses' => [$message->toEmail]],
                'Content'          => [
                    'Simple' => [
                        'Subject' => ['Data' => $message->subject, 'Charset' => 'UTF-8'],
                        'Body'    => array_filter([
                            'Html' => $message->htmlBody === ''
                                ? null
                                : ['Data' => $message->htmlBody, 'Charset' => 'UTF-8'],
                            'Text' => $message->textBody === ''
                                ? null
                                : ['Data' => $message->textBody, 'Charset' => 'UTF-8'],
                        ]),
                    ],
                ],
            ];

            if ($message->replyTo !== '') {
                $request['ReplyToAddresses'] = [$message->replyTo];
            }

            $configurationSet = $message->configurationSet ?? (string) ($this->config['configuration_set'] ?? '');

            if ($configurationSet !== '') {
                $request['ConfigurationSetName'] = $configurationSet;
            }

            // Message tags flow through to SES event records, which is how events
            // are attributed back to a campaign without trusting the recipient.
            if ($message->tags !== []) {
                $request['EmailTags'] = array_map(
                    static fn (string $name, string $value): array => [
                        'Name'  => preg_replace('/[^A-Za-z0-9_-]/', '_', $name) ?? 'tag',
                        'Value' => preg_replace('/[^A-Za-z0-9_-]/', '_', $value) ?? '',
                    ],
                    array_keys($message->tags),
                    array_values($message->tags)
                );
            }

            $result = $this->client()->sendEmail($request);

            return SendResult::accepted((string) ($result['MessageId'] ?? ''));
        } catch (\Throwable $e) {
            return $this->classifyFailure($e);
        }
    }

    public function validateIdentity(string $identity): IdentityStatus
    {
        if (!$this->isConfigured()) {
            return new IdentityStatus($identity, false, 'pending', 'not_checked', 'not_checked', [], 'SES not configured');
        }

        try {
            $result = $this->client()->getEmailIdentity(['EmailIdentity' => $identity]);

            $dkim       = $result['DkimAttributes'] ?? [];
            $dkimStatus = strtolower((string) ($dkim['Status'] ?? 'pending'));

            return new IdentityStatus(
                $identity,
                (bool) ($result['VerifiedForSendingStatus'] ?? false),
                $dkimStatus === 'success' ? 'verified' : ($dkimStatus === 'failed' ? 'failed' : 'pending'),
                'not_checked',
                'not_checked',
                $this->dkimRecordsFrom($identity, $dkim)
            );
        } catch (\Throwable $e) {
            return new IdentityStatus(
                $identity,
                false,
                'pending',
                'not_checked',
                'not_checked',
                [],
                $e->getMessage()
            );
        }
    }

    public function getQuota(): SendQuota
    {
        if (!$this->isConfigured()) {
            return new SendQuota(0.0, 0.0, 0.0, true);
        }

        try {
            $account = $this->client()->getAccount();
            $details = $account['SendQuota'] ?? [];

            return new SendQuota(
                (float) ($details['Max24HourSend'] ?? 0),
                (float) ($details['MaxSendRate'] ?? 0),
                (float) ($details['SentLast24Hours'] ?? 0),
                // ProductionAccessEnabled false means the account is in sandbox.
                !(bool) ($account['ProductionAccessEnabled'] ?? false)
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('Unable to read SES quota', ['error' => $e->getMessage()]);

            // Fail closed: an unknown quota is treated as no headroom rather than
            // unlimited, so a transient API error cannot cause a flood.
            return new SendQuota(0.0, 0.0, 0.0, true);
        }
    }

    public function getReputationMetrics(): ReputationMetrics
    {
        if (!$this->isConfigured()) {
            return new ReputationMetrics();
        }

        try {
            $account = $this->client()->getAccount();

            return new ReputationMetrics(
                0.0,
                0.0,
                (bool) ($account['SendingEnabled'] ?? true),
                (string) (($account['EnforcementStatus'] ?? '') ?: 'HEALTHY')
            );
        } catch (\Throwable) {
            return new ReputationMetrics();
        }
    }

    /**
     * The records a customer must publish. Shown verbatim by the domain
     * verification wizard.
     *
     * @return array<int,array{type:string,name:string,value:string,purpose:string}>
     */
    public function dnsRecordsFor(string $domain): array
    {
        $records = [];

        if ($this->isConfigured()) {
            try {
                $result  = $this->client()->getEmailIdentity(['EmailIdentity' => $domain]);
                $records = $this->dkimRecordsFrom($domain, $result['DkimAttributes'] ?? []);
            } catch (\Throwable) {
                $records = [];
            }
        }

        // SPF and DMARC are the customer's to publish regardless of provider.
        $records[] = [
            'type'    => 'TXT',
            'name'    => $domain,
            'value'   => 'v=spf1 include:amazonses.com ~all',
            'purpose' => 'SPF — authorises Amazon SES to send for this domain. '
                . 'Merge this into an existing SPF record rather than adding a second one.',
        ];

        $records[] = [
            'type'    => 'TXT',
            'name'    => '_dmarc.' . $domain,
            'value'   => 'v=DMARC1; p=none; rua=mailto:dmarc@' . $domain,
            'purpose' => 'DMARC — start at p=none to collect reports, then tighten to quarantine/reject.',
        ];

        return $records;
    }

    /**
     * @param array<string,mixed> $dkim
     * @return array<int,array{type:string,name:string,value:string,purpose:string}>
     */
    private function dkimRecordsFrom(string $domain, array $dkim): array
    {
        $records = [];

        /** @var array<int,string> $tokens */
        $tokens = $dkim['Tokens'] ?? [];

        foreach ($tokens as $token) {
            $records[] = [
                'type'    => 'CNAME',
                'name'    => $token . '._domainkey.' . $domain,
                'value'   => $token . '.dkim.amazonses.com',
                'purpose' => 'DKIM — cryptographically signs your mail. All three records are required.',
            ];
        }

        return $records;
    }

    private function classifyFailure(\Throwable $e): SendResult
    {
        $message = $e->getMessage();

        // Permanent: retrying will produce the same answer and burn reputation.
        foreach ([
            'MessageRejected',
            'MailFromDomainNotVerified',
            'AccountSendingPaused',
            'ConfigurationSetDoesNotExist',
            'InvalidParameterValue',
            'BadRequestException',
        ] as $permanent) {
            if (str_contains($message, $permanent)) {
                return SendResult::rejected($message, $permanent);
            }
        }

        // Transient: throttling and 5xx are worth a backed-off retry.
        return SendResult::failed($message, 'TRANSIENT');
    }

    private function client(): object
    {
        if ($this->client !== null) {
            return $this->client;
        }

        /** @var class-string $clientClass */
        $clientClass = '\Aws\SesV2\SesV2Client';

        if (!class_exists($clientClass)) {
            throw new \RuntimeException(
                'aws/aws-sdk-php is not installed. Run: composer require aws/aws-sdk-php'
            );
        }

        $arguments = [
            'version' => '2019-09-27',
            'region'  => (string) ($this->config['region'] ?? 'us-east-1'),
        ];

        // Static keys only when provided; otherwise the SDK resolves an instance
        // role, which is the preferred production setup.
        if ((string) ($this->config['key'] ?? '') !== '' && (string) ($this->config['secret'] ?? '') !== '') {
            $arguments['credentials'] = [
                'key'    => (string) $this->config['key'],
                'secret' => (string) $this->config['secret'],
            ];
        }

        return $this->client = new $clientClass($arguments);
    }
}
