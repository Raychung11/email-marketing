<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Config;
use App\Core\HttpException;
use App\Database\Connection;
use App\Repositories\MembershipRepository;
use App\Repositories\OrganisationRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Support\TenantContext;

final class OrganisationService
{
    public function __construct(
        private readonly OrganisationRepository $organisations,
        private readonly MembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly UserRepository $users,
        private readonly Connection $connection,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly TenantContext $tenant,
        private readonly AuthManager $auth,
        private readonly AuditService $audit,
    ) {
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes, int $ownerUserId): int
    {
        return $this->connection->transaction(function () use ($attributes, $ownerUserId): int {
            $trustLevel = (string) $this->config->get('antiabuse.default_trust_level', 'new');
            $dailyLimit = (int) $this->config->get(
                'antiabuse.trust_levels.' . $trustLevel . '.daily_send_limit',
                500
            );

            $organisationId = $this->organisations->create(array_merge([
                'trust_level'      => $trustLevel,
                'daily_send_limit' => $dailyLimit,
                'status'           => 'trialing',
                'onboarding_step'  => 1,
            ], $attributes));

            // Default workspace/brand. Agencies add more later without a
            // migration because every brand-scoped table already has the column.
            $this->connection->table('workspaces')->insert([
                'organisation_id' => $organisationId,
                'uuid'            => uuid4(),
                'name'            => (string) ($attributes['name'] ?? 'Default'),
                'slug'            => 'default',
                'is_default'      => 1,
                'created_at'      => $this->clock->nowString(),
                'updated_at'      => $this->clock->nowString(),
            ]);

            $ownerRole = $this->roles->findByKey('OWNER');

            if ($ownerRole === null) {
                throw new \RuntimeException('RBAC has not been seeded. Run: php cron/console.php db:seed');
            }

            $this->memberships->create([
                'organisation_id'    => $organisationId,
                'user_id'            => $ownerUserId,
                'role_id'            => (int) $ownerRole['id'],
                'status'             => 'active',
                'is_default'         => 1,
                'invite_accepted_at' => $this->clock->nowString(),
            ]);

            $this->seedDefaults($organisationId);

            $this->audit->log(
                'organisation_created',
                'organisation',
                $organisationId,
                null,
                ['name' => $attributes['name'] ?? null],
                $organisationId
            );

            return $organisationId;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $organisationId, array $attributes): void
    {
        $before = $this->organisations->findById($organisationId);

        $this->organisations->update($organisationId, $attributes);

        $after = $this->organisations->findById($organisationId);

        if ($after !== null) {
            $this->tenant->refresh($after);
        }

        $this->audit->log(
            'organisation_updated',
            'organisation',
            $organisationId,
            $this->diff($before ?? [], $attributes),
            $attributes
        );
    }

    /**
     * Switch the active organisation.
     *
     * Membership is re-verified here even though the UI only offers valid
     * options: the POST body is user input, and the session is the only thing
     * that may decide tenancy.
     */
    public function switchTo(int $organisationId): void
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            throw HttpException::unauthorized();
        }

        $membership = $this->memberships->activeMembership($userId, $organisationId);

        if ($membership === null) {
            // 404, not 403: do not confirm that this organisation exists.
            throw HttpException::notFound();
        }

        $this->auth->setActiveOrganisation($organisationId);

        $organisation = $this->organisations->findById($organisationId);

        if ($organisation !== null) {
            $this->tenant->bind($organisationId, null, $organisation);
        }

        $this->audit->log('organisation_switched', 'organisation', $organisationId, null, null, $organisationId);
    }

    /** @return array<int,array<string,mixed>> */
    public function forCurrentUser(): array
    {
        $userId = $this->auth->id();

        return $userId === null ? [] : $this->organisations->forUser($userId);
    }

    /**
     * Everything a brand-new organisation should have on day one: a default
     * pipeline, the starter tag vocabulary and the retention segments that make
     * the product immediately useful rather than an empty shell.
     */
    private function seedDefaults(int $organisationId): void
    {
        $now = $this->clock->nowString();

        $pipelineId = $this->connection->table('pipelines')->insert([
            'organisation_id' => $organisationId,
            'name'            => 'Sales pipeline',
            'slug'            => 'sales-pipeline',
            'is_default'      => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        /** @var array<int,array<string,mixed>> $stages */
        $stages = $this->config->get('crm.default_pipeline_stages', []);

        foreach ($stages as $index => $stage) {
            $this->connection->table('pipeline_stages')->insert([
                'organisation_id'   => $organisationId,
                'pipeline_id'       => $pipelineId,
                'key'               => (string) $stage['key'],
                'label'             => (string) $stage['label'],
                'sort_order'        => $index,
                'probability'       => (int) $stage['probability'],
                'is_won'            => $stage['is_won'] ? 1 : 0,
                'is_lost'           => $stage['is_lost'] ? 1 : 0,
                'stale_after_hours' => $stage['key'] === 'new' ? 24 : null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
        }

        foreach ([
            'VIP'                   => '#7c3aed',
            'Hot Lead'              => '#dc2626',
            'Previous Customer'     => '#0891b2',
            'High Value'            => '#059669',
            'Newsletter Subscriber' => '#4f46e5',
        ] as $name => $colour) {
            $this->connection->table('tags')->insert([
                'organisation_id' => $organisationId,
                'name'            => $name,
                'slug'            => str_slug($name),
                'colour'          => $colour,
                'is_system'       => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        $this->seedRetentionSegments($organisationId);
    }

    private function seedRetentionSegments(int $organisationId): void
    {
        $now = $this->clock->nowString();

        $templates = [
            'inactive_90' => [
                'name'       => 'Inactive 90 days',
                'definition' => [
                    'match' => 'all',
                    'rules' => [
                        ['field' => 'last_purchase', 'operator' => 'before', 'value' => 'now-90days'],
                        ['field' => 'customer_status', 'operator' => 'in', 'value' => ['customer', 'repeat_customer', 'vip']],
                    ],
                ],
            ],
            'inactive_180' => [
                'name'       => 'Inactive 180 days',
                'definition' => [
                    'match' => 'all',
                    'rules' => [
                        ['field' => 'last_purchase', 'operator' => 'before', 'value' => 'now-180days'],
                    ],
                ],
            ],
            'vip' => [
                'name'       => 'VIP customers',
                'definition' => [
                    'match' => 'all',
                    'rules' => [
                        ['field' => 'customer_status', 'operator' => 'equals', 'value' => 'vip'],
                    ],
                ],
            ],
            'repeat_customer' => [
                'name'       => 'Repeat customers',
                'definition' => [
                    'match' => 'all',
                    'rules' => [
                        ['field' => 'purchase_count', 'operator' => 'gte', 'value' => 2],
                    ],
                ],
            ],
            'at_risk' => [
                'name'       => 'At-risk customers',
                'definition' => [
                    'match' => 'all',
                    'rules' => [
                        ['field' => 'purchase_count', 'operator' => 'gte', 'value' => 2],
                        ['field' => 'last_purchase', 'operator' => 'before', 'value' => 'now-120days'],
                    ],
                ],
            ],
        ];

        foreach ($templates as $key => $template) {
            $this->connection->table('segments')->insert([
                'organisation_id' => $organisationId,
                'uuid'            => uuid4(),
                'name'            => $template['name'],
                'slug'            => str_slug($template['name']),
                'match_type'      => 'all',
                'definition'      => json_encode($template['definition'], JSON_UNESCAPED_SLASHES),
                'template_key'    => $key,
                'is_system'       => 1,
                'created_via'     => 'template',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function diff(array $before, array $changes): array
    {
        $old = [];

        foreach (array_keys($changes) as $key) {
            if (array_key_exists($key, $before)) {
                $old[$key] = $before[$key];
            }
        }

        return $old;
    }
}
