<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Database\Connection;
use App\Repositories\OrganisationRepository;
use App\Support\TenantContext;

/**
 * Sign-up and the setup wizard.
 *
 * The wizard order is deliberate: identity and compliance context (country,
 * timezone, postal address) are captured before anything can be sent, because
 * those are preconditions for a lawful marketing email, not nice-to-haves.
 */
final class OnboardingService
{
    public const STEPS = [
        1 => ['key' => 'company',   'label' => 'Company'],
        2 => ['key' => 'locale',    'label' => 'Country & timezone'],
        3 => ['key' => 'address',   'label' => 'Business address'],
        4 => ['key' => 'domain',    'label' => 'Sending domain'],
        5 => ['key' => 'sender',    'label' => 'Sender identity'],
        6 => ['key' => 'contacts',  'label' => 'Import customers'],
        7 => ['key' => 'consent',   'label' => 'Consent settings'],
        8 => ['key' => 'campaign',  'label' => 'First campaign'],
    ];

    public function __construct(
        private readonly AuthService $authService,
        private readonly OrganisationService $organisations,
        private readonly OrganisationRepository $organisationRepository,
        private readonly AuthManager $auth,
        private readonly Connection $connection,
        private readonly TenantContext $tenant,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create a user and their first organisation in one transaction, and sign
     * them in.
     *
     * @return array{user_id:int,organisation_id:int}
     */
    public function registerOrganisationWithOwner(
        string $organisationName,
        string $email,
        string $password,
        string $country = 'US',
        string $timezone = 'UTC',
        string $currency = 'USD',
        string $firstName = '',
        string $lastName = '',
    ): array {
        return $this->connection->transaction(function () use (
            $organisationName,
            $email,
            $password,
            $country,
            $timezone,
            $currency,
            $firstName,
            $lastName,
        ): array {
            $userId = $this->authService->createUser($email, $password, [
                'first_name' => $firstName !== '' ? $firstName : null,
                'last_name'  => $lastName !== '' ? $lastName : null,
                'timezone'   => $timezone,
            ]);

            $organisationId = $this->organisations->create([
                'name'          => $organisationName,
                'country'       => strtoupper($country),
                'timezone'      => $timezone,
                'currency'      => strtoupper($currency),
                'contact_email' => $email,
            ], $userId);

            $this->auth->login($userId, $organisationId);

            $organisation = $this->organisationRepository->findById($organisationId);

            if ($organisation !== null) {
                $this->tenant->bind($organisationId, null, $organisation);
            }

            return ['user_id' => $userId, 'organisation_id' => $organisationId];
        });
    }

    /** @return array{step:int,total:int,percent:int,completed:bool,label:string} */
    public function progress(): array
    {
        $organisation = $this->tenant->organisation();
        $step         = max(1, (int) ($organisation['onboarding_step'] ?? 1));
        $total        = count(self::STEPS);
        $completed    = ($organisation['onboarding_completed_at'] ?? null) !== null;

        return [
            'step'      => min($step, $total),
            'total'     => $total,
            'percent'   => $completed ? 100 : (int) round((($step - 1) / $total) * 100),
            'completed' => $completed,
            'label'     => self::STEPS[min($step, $total)]['label'] ?? '',
        ];
    }

    /** @param array<string,mixed> $data */
    public function completeStep(int $step, array $data): void
    {
        $organisationId = $this->tenant->organisationId();

        $attributes = match ($step) {
            1 => [
                'name'     => $data['name'] ?? null,
                'industry' => $data['industry'] ?? null,
                'website'  => $data['website'] ?? null,
            ],
            2 => [
                'country'  => strtoupper((string) ($data['country'] ?? 'US')),
                'timezone' => $data['timezone'] ?? 'UTC',
                'currency' => strtoupper((string) ($data['currency'] ?? 'USD')),
            ],
            3 => [
                'address_line1'    => $data['address_line1'] ?? null,
                'address_line2'    => $data['address_line2'] ?? null,
                'address_city'     => $data['address_city'] ?? null,
                'address_state'    => $data['address_state'] ?? null,
                'address_postcode' => $data['address_postcode'] ?? null,
                'address_country'  => strtoupper((string) ($data['address_country'] ?? $data['country'] ?? 'US')),
                'contact_phone'    => $data['contact_phone'] ?? null,
                'contact_email'    => $data['contact_email'] ?? null,
            ],
            5 => [
                'default_sender_name'  => $data['default_sender_name'] ?? null,
                'default_sender_email' => $data['default_sender_email'] ?? null,
                'reply_to_email'       => $data['reply_to_email'] ?? null,
            ],
            7 => [
                'require_campaign_approval' => !empty($data['require_campaign_approval']) ? 1 : 0,
            ],
            default => [],
        };

        $attributes = array_filter($attributes, static fn ($value): bool => $value !== null);

        $attributes['onboarding_step'] = min($step + 1, count(self::STEPS));

        if ($step >= count(self::STEPS)) {
            $attributes['onboarding_completed_at'] = $this->clock->nowString();
            $attributes['onboarding_step']         = count(self::STEPS);
        }

        $this->organisations->update($organisationId, $attributes);
    }

    public function skip(): void
    {
        $this->organisations->update($this->tenant->organisationId(), [
            'onboarding_completed_at' => $this->clock->nowString(),
            'onboarding_step'         => count(self::STEPS),
        ]);
    }

    /**
     * Which setup items are still outstanding. Drives the dashboard checklist
     * and, for the compliance-critical ones, the campaign validator.
     *
     * @return array<int,array{key:string,label:string,done:bool,critical:bool,url:string}>
     */
    public function checklist(): array
    {
        $organisation = $this->tenant->organisation();
        $orgId        = (int) ($organisation['id'] ?? 0);

        $hasDomain = $orgId > 0 && $this->connection->table('sending_domains')
            ->where('organisation_id', '=', $orgId)
            ->where('status', '=', 'verified')
            ->exists();

        $hasContacts = $orgId > 0 && $this->connection->table('contacts')
            ->where('organisation_id', '=', $orgId)
            ->whereNull('deleted_at')
            ->exists();

        return [
            [
                'key'      => 'company',
                'label'    => 'Add your business details',
                'done'     => ($organisation['name'] ?? '') !== '',
                'critical' => false,
                'url'      => '/settings',
            ],
            [
                'key'      => 'address',
                'label'    => 'Add your physical postal address',
                'done'     => ($organisation['address_line1'] ?? '') !== ''
                    && ($organisation['address_city'] ?? '') !== '',
                // Required in every marketing footer for US commercial email.
                'critical' => true,
                'url'      => '/settings#address',
            ],
            [
                'key'      => 'sender',
                'label'    => 'Set a default sender name and address',
                'done'     => ($organisation['default_sender_email'] ?? '') !== '',
                'critical' => true,
                'url'      => '/settings#sender',
            ],
            [
                'key'      => 'domain',
                'label'    => 'Verify a sending domain',
                'done'     => $hasDomain,
                'critical' => true,
                'url'      => '/settings/domains',
            ],
            [
                'key'      => 'contacts',
                'label'    => 'Add or import your customers',
                'done'     => $hasContacts,
                'critical' => false,
                'url'      => '/contacts/import',
            ],
        ];
    }
}
