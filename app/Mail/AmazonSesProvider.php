<?php

declare(strict_types=1);

namespace App\Mail;

use App\Core\Logger;
use App\Mail\Aws\SesClient;
use App\Mail\Aws\SesException;
use App\Mail\Aws\SignatureV4;
use App\Support\CurlHttpClient;
use App\Support\HttpClient;

/**
 * Amazon SES over the SESv2 REST API.
 *
 * No SDK. The AWS SDK is sixty megabytes and several thousand files to make
 * four API calls, which is a poor trade on the shared hosting most of this
 * product's customers run, and it would be the only runtime dependency in the
 * application. SesClient speaks to the same endpoints and returns the same
 * array shapes, so everything below reads identically either way.
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
    /**
     * Errors where retrying produces the same answer. Retrying these burns
     * sending reputation for nothing, so they are rejected outright rather than
     * queued for another four attempts. Everything else — throttling, 5xx, a
     * dropped connection — gets the backoff.
     */
    private const PERMANENT_FAILURES = [
        'MessageRejected',
        'MailFromDomainNotVerified',
        'AccountSendingPausedException',
        'AccountSendingPaused',
        'SendingPausedException',
        'ConfigurationSetDoesNotExistException',
        'ConfigurationSetDoesNotExist',
        'InvalidParameterValue',
        'BadRequestException',
        'NotFoundException',
        'AccessDeniedException',
    ];

    private ?SesClient $client = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ?Logger $logger = null,
        private readonly ?HttpClient $http = null,
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
        return (string) ($this->config['region'] ?? '') !== ''
            && (string) ($this->config['key'] ?? '') !== ''
            && (string) ($this->config['secret'] ?? '') !== '';
    }

    public function send(OutboundMessage $message): SendResult
    {
        if (!$this->isConfigured()) {
            return SendResult::rejected(
                'Amazon SES is not configured. Set AWS_REGION, AWS_ACCESS_KEY_ID and '
                . 'AWS_SECRET_ACCESS_KEY in .env.',
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
            $result = $this->identity($identity);

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
                $result  = $this->identity($domain);
                $records = $this->dkimRecordsFrom($domain, $result['DkimAttributes'] ?? []);
            } catch (\Throwable $e) {
                $this->logger?->warning('Could not obtain DKIM records from SES', [
                    'domain' => $domain,
                    'error'  => $e->getMessage(),
                ]);

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
     * The SES identity for a domain, registering it first if SES has never seen
     * it.
     *
     * Without this, adding a sending domain asked SES about an identity that did
     * not exist, got a 404, and returned no DKIM records at all — so the wizard
     * showed SPF and DMARC only, the customer published those, and verification
     * could never pass because DKIM alone decides it. Registering is what makes
     * SES generate the three CNAME tokens in the first place.
     *
     * @return array<string,mixed>
     */
    private function identity(string $domain): array
    {
        try {
            return $this->client()->getEmailIdentity(['EmailIdentity' => $domain]);
        } catch (SesException $e) {
            if ($e->awsCode !== 'NotFoundException') {
                throw $e;
            }
        }

        $created = $this->client()->createEmailIdentity(['EmailIdentity' => $domain]);

        // The create response already carries the tokens; only ask again if this
        // particular response did not include them.
        if (($created['DkimAttributes']['Tokens'] ?? []) !== []) {
            return $created;
        }

        return $this->client()->getEmailIdentity(['EmailIdentity' => $domain]);
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

        // The API reports the error type explicitly, so prefer it to guessing
        // from the message text.
        if ($e instanceof SesException && $e->awsCode !== '') {
            return $this->isPermanent($e->awsCode)
                ? SendResult::rejected($message, $e->awsCode)
                : SendResult::failed($message, 'TRANSIENT');
        }

        foreach (self::PERMANENT_FAILURES as $permanent) {
            if (str_contains($message, $permanent)) {
                return SendResult::rejected($message, $permanent);
            }
        }

        // Transient: throttling and 5xx are worth a backed-off retry.
        return SendResult::failed($message, 'TRANSIENT');
    }

    private function isPermanent(string $code): bool
    {
        return in_array($code, self::PERMANENT_FAILURES, true);
    }

    private function client(): SesClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $region = (string) ($this->config['region'] ?? 'us-east-1');

        return $this->client = new SesClient(
            new SignatureV4(
                (string) ($this->config['key'] ?? ''),
                (string) ($this->config['secret'] ?? ''),
                $region,
                'ses',
                (string) ($this->config['session_token'] ?? '')
            ),
            $this->http ?? new CurlHttpClient(),
            $region,
            (int) ($this->config['timeout'] ?? 30)
        );
    }
}
