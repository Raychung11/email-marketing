<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ApiKeyService;
use App\Services\SuppressionService;
use Tests\Support\TestCase;

/**
 * §48 — the REST API.
 *
 * The tenant is derived from the key. An integration is not a way around consent
 * or suppression, and the tests below are what keeps that true.
 */
final class ApiTest extends TestCase
{
    public function testARequestWithoutAKeyIsRejected(): void
    {
        $this->createOrganisation();

        $response = $this->apiRequest('GET', '/api/v1/contacts', '');

        $this->assertStatus(401, $response);
        $this->assertContainsString('API key is required', $response->body());
    }

    public function testAnInvalidKeyIsRejected(): void
    {
        $this->createOrganisation();

        $response = $this->apiRequest('GET', '/api/v1/contacts', 'aigh_not_a_real_key');

        $this->assertStatus(401, $response);
    }

    public function testTheKeyDecidesTheTenant(): void
    {
        $orgA = $this->createOrganisation();
        $orgB = $this->createOrganisation();

        $this->bindTenant($orgA['organisation_id']);
        $this->createContact(['email' => 'a-only@example.com']);
        $keyA = $this->issueKey($orgA['organisation_id'], ['contacts:read']);

        $this->bindTenant($orgB['organisation_id']);
        $this->createContact(['email' => 'b-only@example.com']);

        $response = $this->apiRequest('GET', '/api/v1/contacts', $keyA);
        $payload  = json_decode($response->body(), true);

        $this->assertStatus(200, $response);
        $this->assertCount(1, $payload['data']);
        $this->assertSame('a-only@example.com', $payload['data'][0]['email'], 'Only the key owner\'s contacts');
    }

    public function testScopesAreEnforcedPerEndpoint(): void
    {
        $org       = $this->createOrganisation();
        $readOnly  = $this->issueKey($org['organisation_id'], ['contacts:read']);

        $this->assertStatus(200, $this->apiRequest('GET', '/api/v1/contacts', $readOnly));

        $write = $this->apiRequest('POST', '/api/v1/contacts', $readOnly, ['email' => 'new@example.com']);

        $this->assertStatus(403, $write);
        $this->assertContainsString('contacts:write', $write->body(), 'The response names the missing scope');
    }

    public function testARevokedKeyStopsWorking(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var ApiKeyService $keys */
        $keys   = $this->container->make(ApiKeyService::class);
        $issued = $keys->create('Integration', ['contacts:read']);

        $this->assertStatus(200, $this->apiRequest('GET', '/api/v1/contacts', $issued['key']));

        $keys->revoke($issued['id']);

        $this->assertStatus(401, $this->apiRequest('GET', '/api/v1/contacts', $issued['key']));
    }

    public function testOnlyAHashOfTheKeyIsStored(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);

        /** @var ApiKeyService $keys */
        $keys   = $this->container->make(ApiKeyService::class);
        $issued = $keys->create('Integration', ['contacts:read']);

        $row = $this->connection->selectOne('SELECT * FROM api_keys WHERE id = ?', [$issued['id']]);

        $this->assertNotSame($issued['key'], (string) $row['key_hash'], 'The plaintext key is not stored');
        $this->assertSame(hash('sha256', $issued['key']), (string) $row['key_hash']);
        $this->assertContainsString((string) $row['key_prefix'], $issued['key'], 'Only a prefix is kept for display');
    }

    public function testContactsCanBeCreatedAndUpsertedThroughTheApi(): void
    {
        $org = $this->createOrganisation();
        $key = $this->issueKey($org['organisation_id'], ['contacts:read', 'contacts:write']);

        $created = $this->apiRequest('POST', '/api/v1/contacts', $key, [
            'email'      => 'api@example.com',
            'first_name' => 'Api',
            'country'    => 'US',
        ]);

        $this->assertStatus(201, $created);

        $payload = json_decode($created->body(), true);
        $this->assertSame('api@example.com', $payload['data']['email']);
        $this->assertNotNull($payload['data']['uuid'], 'Integrations address contacts by UUID');

        // Posting the same address again updates rather than erroring, which is
        // what an integration retry needs.
        $again = $this->apiRequest('POST', '/api/v1/contacts', $key, [
            'email'      => 'api@example.com',
            'first_name' => 'Updated',
        ]);

        $this->assertStatus(200, $again);
        $this->assertSame('Updated', json_decode($again->body(), true)['data']['first_name']);
    }

    public function testInternalIdsAreNeverExposed(): void
    {
        $org = $this->createOrganisation();
        $this->bindTenant($org['organisation_id']);
        $this->createContact(['email' => 'hidden@example.com']);

        $key      = $this->issueKey($org['organisation_id'], ['contacts:read']);
        $response = $this->apiRequest('GET', '/api/v1/contacts', $key);
        $payload  = json_decode($response->body(), true);

        $this->assertFalse(array_key_exists('id', $payload['data'][0]), 'No internal primary key');
        $this->assertFalse(array_key_exists('organisation_id', $payload['data'][0]), 'No tenant key');
    }

    public function testAContactCreatedThroughTheApiWithNoConsentIsNotMarketable(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $key = $this->issueKey($org['organisation_id'], ['contacts:read', 'contacts:write']);

        $created = $this->apiRequest('POST', '/api/v1/contacts', $key, [
            'email'   => 'api-au@example.com.au',
            'country' => 'AU',
        ]);

        $uuid     = json_decode($created->body(), true)['data']['uuid'];
        $response = $this->apiRequest('GET', '/api/v1/contacts/' . $uuid, $key);
        $payload  = json_decode($response->body(), true);

        $this->assertFalse(
            $payload['data']['eligibility']['allowed'],
            'An integration cannot conjure consent it has no evidence for'
        );
        $this->assertSame('AU_CONSENT_UNKNOWN', $payload['data']['eligibility']['reason']);
    }

    public function testConsentCanBeReportedWithEvidence(): void
    {
        $org = $this->createOrganisation(['country' => 'AU']);
        $key = $this->issueKey($org['organisation_id'], ['contacts:read', 'contacts:write']);

        $created = $this->apiRequest('POST', '/api/v1/contacts', $key, [
            'email'   => 'website-signup@example.com.au',
            'country' => 'AU',
            'consent' => [
                'status'           => 'granted',
                'consent_type'     => 'express',
                'source_reference' => 'checkout form',
                'consent_text'     => 'Email me maintenance reminders',
                'ip_address'       => '198.51.100.77',
            ],
        ]);

        $uuid    = json_decode($created->body(), true)['data']['uuid'];
        $payload = json_decode($this->apiRequest('GET', '/api/v1/contacts/' . $uuid, $key)->body(), true);

        $this->assertTrue($payload['data']['eligibility']['allowed'], 'With evidence, the contact is marketable');
        $this->assertSame('granted', $payload['data']['consent']['status']);
        $this->assertSame('198.51.100.77', $payload['data']['consent']['ip_address'], 'The evidence is retained');
    }

    public function testTheApiCannotClearASuppression(): void
    {
        $org = $this->createOrganisation(['country' => 'US']);
        $this->bindTenant($org['organisation_id']);

        /** @var SuppressionService $suppressions */
        $suppressions = $this->container->make(SuppressionService::class);
        $suppressions->suppressForComplaint('complained@example.com', 'ses');

        $key = $this->issueKey($org['organisation_id'], ['contacts:read', 'contacts:write']);

        // An integration reports an opt-in for an address that complained. The
        // consent record is appended — but the suppression stands.
        $created = $this->apiRequest('POST', '/api/v1/contacts', $key, [
            'email'   => 'complained@example.com',
            'country' => 'US',
            'consent' => ['status' => 'granted', 'consent_type' => 'express'],
        ]);

        $uuid    = json_decode($created->body(), true)['data']['uuid'];
        $payload = json_decode($this->apiRequest('GET', '/api/v1/contacts/' . $uuid, $key)->body(), true);

        $this->assertFalse($payload['data']['eligibility']['allowed']);
        $this->assertSame('SUPPRESSED_COMPLAINT', $payload['data']['eligibility']['reason']);

        $this->bindTenant($org['organisation_id']);
        $this->assertTrue($suppressions->isSuppressed('complained@example.com'));
    }

    public function testCsrfDoesNotApplyToApiRequests(): void
    {
        $org = $this->createOrganisation();
        $key = $this->issueKey($org['organisation_id'], ['contacts:write']);

        // The API authenticates with a bearer token, not a cookie, so there is no
        // CSRF surface — and requiring a token would make it unusable.
        $response = $this->apiRequest('POST', '/api/v1/contacts', $key, ['email' => 'nocsrf@example.com']);

        $this->assertStatus(201, $response);
    }

    public function testRateLimitHeadersAreReturned(): void
    {
        $org = $this->createOrganisation();
        $key = $this->issueKey($org['organisation_id'], ['contacts:read']);

        $headers = $this->apiRequest('GET', '/api/v1/contacts', $key)->headers();

        $this->assertTrue(isset($headers['X-RateLimit-Limit']));
        $this->assertTrue(isset($headers['X-RateLimit-Remaining']));
    }

    public function testPingIsAvailableWithoutAKey(): void
    {
        $response = $this->apiRequest('GET', '/api/v1/ping', '');

        $this->assertStatus(200, $response);
        $this->assertContainsString('"ok":true', $response->body());
    }

    /** @param array<int,string> $scopes */
    private function issueKey(int $organisationId, array $scopes): string
    {
        $this->bindTenant($organisationId);

        /** @var ApiKeyService $keys */
        $keys = $this->container->make(ApiKeyService::class);

        return $keys->create('Test integration', $scopes)['key'];
    }
}
