<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Services\ApiKeyService;
use Tests\Support\TestCase;

/**
 * Revoking every API key, as a thing somebody does on purpose.
 *
 * A password reset revokes sessions and deliberately does NOT touch API keys.
 * They are separate credentials, and quietly breaking a customer's integration
 * — whose only symptom is a 401 nobody can explain — is not something to do on
 * an inference about what the person was worried about. So it is a control they
 * press, having read what it does.
 *
 * Which makes these the things worth proving: that it reaches every live key
 * and no other tenant's, that only somebody who owns integrations can do it,
 * and that the audit record says what happened without becoming a place to
 * steal a key from.
 */
final class ApiKeyRevocationTest extends TestCase
{
    public function testRevokingAllKeysStopsEveryOneOfThemAuthenticating(): void
    {
        $org = $this->createOrganisation();

        $keys  = $this->keys();
        $first  = $keys->create('Zapier', ['contacts:read']);
        $second = $keys->create('Warehouse sync', ['contacts:read', 'contacts:write']);

        $this->assertSame(2, $keys->activeCount());
        $this->assertNotNull($keys->resolve($first['key']), 'Both work to begin with');
        $this->assertNotNull($keys->resolve($second['key']));

        $this->assertSame(2, $keys->revokeAllForOrganisation());

        $this->assertNull($keys->resolve($first['key']), 'And neither works afterwards');
        $this->assertNull($keys->resolve($second['key']));
        $this->assertSame(0, $keys->activeCount());
    }

    public function testRevokingAgainReportsNothingToDoRatherThanLying(): void
    {
        $this->createOrganisation();

        $keys = $this->keys();
        $keys->create('Zapier', ['contacts:read']);

        $this->assertSame(1, $keys->revokeAllForOrganisation());
        $this->assertSame(0, $keys->revokeAllForOrganisation(), 'The second press has nothing left to revoke');
    }

    /**
     * The UPDATE is guarded on `revoked_at IS NULL`, so a key somebody revoked
     * individually a week ago keeps the date it was actually revoked rather
     * than having today's written over it. Audit trails that quietly move are
     * worse than none.
     */
    public function testAnAlreadyRevokedKeyKeepsItsOriginalTimestamp(): void
    {
        $this->createOrganisation();

        $keys   = $this->keys();
        $issued = $keys->create('Retired integration', ['contacts:read']);

        $this->connection->execute(
            'UPDATE api_keys SET revoked_at = ? WHERE id = ?',
            ['2026-01-05 09:00:00', $issued['id']]
        );

        $keys->create('Still live', ['contacts:read']);

        $this->assertSame(1, $keys->revokeAllForOrganisation(), 'Only the live one is touched');

        $this->assertSame(
            '2026-01-05 09:00:00',
            (string) $this->connection->scalar('SELECT revoked_at FROM api_keys WHERE id = ?', [$issued['id']])
        );
    }

    public function testAnExpiredKeyIsNotCountedAsActive(): void
    {
        $this->createOrganisation();

        $keys   = $this->keys();
        $issued = $keys->create('Expired', ['contacts:read']);

        $this->connection->execute(
            'UPDATE api_keys SET expires_at = ? WHERE id = ?',
            ['2026-01-01 00:00:00', $issued['id']]
        );

        // resolve() already refuses it, so counting it would overstate what
        // revoking achieves.
        $this->assertNull($keys->resolve($issued['key']));
        $this->assertSame(0, $keys->activeCount());
    }

    public function testRevocationNeverReachesAnotherTenantsKeys(): void
    {
        $theirs = $this->createOrganisation(['email' => 'theirs@example.test']);
        $keys   = $this->keys();
        $safe   = $keys->create('Their integration', ['contacts:read']);

        $ours = $this->createOrganisation(['email' => 'ours@example.test']);
        $this->actingAs($ours['user_id'], $ours['organisation_id']);
        $keys->create('Our integration', ['contacts:read']);

        $this->assertSame(1, $keys->revokeAllForOrganisation(), 'Only ours');

        // resolve() runs outside a bound tenant by design — the organisation is
        // a result of authentication, never an input to it — so this is the real
        // test of whether their key still works.
        $this->assertNotNull($keys->resolve($safe['key']), 'Their key is untouched');
    }

    public function testTheAuditRecordNamesTheKeysWithoutBeingAPlaceToStealOne(): void
    {
        $this->createOrganisation();

        $keys   = $this->keys();
        $issued = $keys->create('Zapier', ['contacts:read']);

        $keys->revokeAllForOrganisation();

        $row = $this->connection->selectOne(
            "SELECT new_values FROM audit_logs WHERE action = 'api_keys_revoked_all' ORDER BY id DESC"
        );

        $this->assertNotNull($row, 'The revocation is audited');

        $recorded = (string) ($row['new_values'] ?? '');

        $this->assertContainsString('Zapier', $recorded, 'Which key, by name');
        $this->assertNotContainsString($issued['key'], $recorded, 'Never the key itself');
        $this->assertNotContainsString(
            hash('sha256', $issued['key']),
            $recorded,
            'Nor its hash, which is the credential as far as a database dump is concerned'
        );
    }

    // --------------------------------------------------------- the HTTP side

    public function testAnAdminCanRevokeFromTheProfilePage(): void
    {
        $org   = $this->createOrganisation();
        $admin = $this->createUserWithRole($org['organisation_id'], 'ADMIN');

        $this->actingAs($admin, $org['organisation_id']);

        $keys   = $this->keys();
        $issued = $keys->create('Zapier', ['contacts:read']);

        $page = $this->get('/settings/profile');

        $this->assertStatus(200, $page);
        $this->assertContainsString('Revoke all API keys', $page->body(), 'The control is offered');

        $response = $this->post('/settings/profile/api-keys/revoke-all');

        $this->assertStatus(302, $response, 'It redirects back with a message');
        $this->assertNull($keys->resolve($issued['key']));
    }

    public function testAMarketerIsNeitherOfferedItNorAllowedIt(): void
    {
        $org       = $this->createOrganisation();
        $marketer  = $this->createUserWithRole($org['organisation_id'], 'MARKETER');

        $this->actingAs($org['user_id'], $org['organisation_id']);
        $issued = $this->keys()->create('Zapier', ['contacts:read']);

        $this->actingAs($marketer, $org['organisation_id']);

        $page = $this->get('/settings/profile');

        $this->assertStatus(200, $page, 'They can still see their own profile');
        $this->assertNotContainsString(
            'Revoke all API keys',
            $page->body(),
            'But are not shown a control they cannot use'
        );

        $response = $this->post('/settings/profile/api-keys/revoke-all');

        $this->assertStatus(403, $response, 'And the route refuses them, not just the page');

        $this->actingAs($org['user_id'], $org['organisation_id']);
        $this->assertNotNull($this->keys()->resolve($issued['key']), 'And the key still works');
    }

    public function testTheProfilePageDoesNotOfferTheControlWhenThereAreNoKeys(): void
    {
        $org = $this->createOrganisation();
        $this->actingAs($org['user_id'], $org['organisation_id']);

        $page = $this->get('/settings/profile');

        $this->assertStatus(200, $page);
        $this->assertContainsString('No API keys are active', $page->body());
        $this->assertNotContainsString('Revoke all API keys', $page->body());
    }

    private function keys(): ApiKeyService
    {
        return $this->container->make(ApiKeyService::class);
    }
}
