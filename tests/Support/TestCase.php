<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Application;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\FrozenClock;
use App\Core\Request;
use App\Core\Response;
use App\Core\Clock;
use App\Database\Connection;
use App\Database\SchemaDumper;
use App\Repositories\OrganisationRepository;
use App\Services\AuthManager;
use App\Support\TenantContext;

/**
 * Base test case.
 *
 * Each test gets a pristine in-memory SQLite database. The schema is rendered
 * once from the real migration files and replayed per test, which keeps the suite
 * fast while still exercising the actual migrations — a hand-maintained test
 * schema would drift and then the tests would be proving nothing.
 */
abstract class TestCase
{
    protected Application $app;

    protected Container $container;

    protected Connection $connection;

    protected TenantContext $tenant;

    protected FrozenClock $clock;

    /** @var array<int,string>|null */
    private static ?array $schemaStatements = null;

    // ----------------------------------------------------------- assertions

    private int $assertions = 0;

    /** @var array<int,string> */
    private array $failures = [];

    public function setUp(): void
    {
        $this->app        = Application::bootForTesting(dirname(__DIR__, 2));
        $this->container  = $this->app->container();
        $this->connection = $this->container->make(Connection::class);
        $this->tenant     = $this->container->make(TenantContext::class);

        // A frozen clock makes every time-dependent rule (consent age, inactivity
        // windows, lockouts, backoff) deterministic.
        $this->clock = new FrozenClock('2026-06-15 12:00:00');
        $this->container->instance(Clock::class, $this->clock);

        $this->migrate();
        $this->seed();
    }

    public function tearDown(): void
    {
        Container::setInstance(null);
    }

    private function migrate(): void
    {
        if (self::$schemaStatements === null) {
            $dumper                 = new SchemaDumper(dirname(__DIR__, 2) . '/database/migrations');
            self::$schemaStatements = $dumper->dump('sqlite');
        }

        foreach (self::$schemaStatements as $statement) {
            $this->connection->raw($statement);
        }
    }

    private function seed(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/database/seeds/*.php') ?: [] as $file) {
            /** @var callable(Container):void $seeder */
            $seeder = require $file;
            $seeder($this->container);
        }
    }

    // -------------------------------------------------------------- factories

    /**
     * Create an organisation with an owner, and bind the tenant.
     *
     * @param array<string,mixed> $attributes
     * @return array{organisation_id:int,user_id:int,organisation:array<string,mixed>}
     */
    protected function createOrganisation(array $attributes = []): array
    {
        $suffix = bin2hex(random_bytes(4));

        /** @var \App\Services\OnboardingService $onboarding */
        $onboarding = $this->container->make(\App\Services\OnboardingService::class);

        $result = $onboarding->registerOrganisationWithOwner(
            (string) ($attributes['name'] ?? 'Test Org ' . $suffix),
            (string) ($attributes['email'] ?? 'owner-' . $suffix . '@example.test'),
            (string) ($attributes['password'] ?? 'correct horse battery staple'),
            (string) ($attributes['country'] ?? 'US'),
            (string) ($attributes['timezone'] ?? 'UTC'),
            (string) ($attributes['currency'] ?? 'USD'),
        );

        /** @var OrganisationRepository $organisations */
        $organisations = $this->container->make(OrganisationRepository::class);
        $organisation  = $organisations->findById($result['organisation_id']);

        return [
            'organisation_id' => $result['organisation_id'],
            'user_id'         => $result['user_id'],
            'organisation'    => $organisation ?? [],
        ];
    }

    /** Bind the tenant context without going through HTTP. */
    protected function bindTenant(int $organisationId): array
    {
        /** @var OrganisationRepository $organisations */
        $organisations = $this->container->make(OrganisationRepository::class);
        $organisation  = $organisations->findById($organisationId);

        $this->tenant->clear();
        $this->tenant->bind($organisationId, null, $organisation ?? []);

        return $organisation ?? [];
    }

    /**
     * Sign out. Registering an organisation signs its owner in — that is the real
     * behaviour — so a test that needs a guest has to say so.
     */
    protected function signOut(): void
    {
        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);
        $auth->logout();
    }

    protected function actingAs(int $userId, int $organisationId): void
    {
        /** @var AuthManager $auth */
        $auth = $this->container->make(AuthManager::class);
        $auth->login($userId, $organisationId);

        $this->bindTenant($organisationId);
    }

    /**
     * Create a contact directly through the service, so consent and dedup rules
     * apply exactly as they do in the application.
     *
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $consent
     */
    protected function createContact(array $attributes = [], array $consent = []): int
    {
        /** @var \App\Services\ContactService $contacts */
        $contacts = $this->container->make(\App\Services\ContactService::class);

        return $contacts->create(array_merge([
            'email'      => 'contact-' . bin2hex(random_bytes(5)) . '@example.test',
            'first_name' => 'Test',
            'last_name'  => 'Contact',
            'country'    => 'US',
        ], $attributes), $consent);
    }

    /** @param array<int,string> $permissions replaces the role's grants entirely */
    protected function createUserWithRole(int $organisationId, string $roleKey, ?string $email = null): int
    {
        $email ??= 'member-' . bin2hex(random_bytes(4)) . '@example.test';

        /** @var \App\Services\AuthService $authService */
        $authService = $this->container->make(\App\Services\AuthService::class);
        $userId      = $authService->createUser($email, 'correct horse battery staple');

        /** @var \App\Repositories\RoleRepository $roles */
        $roles = $this->container->make(\App\Repositories\RoleRepository::class);
        $role  = $roles->findByKey($roleKey);

        if ($role === null) {
            throw new \RuntimeException("Role {$roleKey} is not seeded.");
        }

        /** @var \App\Repositories\MembershipRepository $memberships */
        $memberships = $this->container->make(\App\Repositories\MembershipRepository::class);
        $memberships->create([
            'organisation_id'    => $organisationId,
            'user_id'            => $userId,
            'role_id'            => (int) $role['id'],
            'status'             => 'active',
            'invite_accepted_at' => $this->clock->nowString(),
        ]);

        return $userId;
    }

    // ------------------------------------------------------------ HTTP layer

    /**
     * Dispatch a request through the full stack: middleware, router, controller.
     *
     * @param array<string,mixed> $query
     */
    protected function get(string $path, array $query = []): Response
    {
        return $this->app->handle(new Request(
            $query,
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '203.0.113.10'],
        ));
    }

    /**
     * @param array<string,mixed> $body CSRF token is added automatically
     */
    protected function post(string $path, array $body = []): Response
    {
        /** @var Csrf $csrf */
        $csrf = $this->container->make(Csrf::class);

        $body['_token'] ??= $csrf->token();

        return $this->app->handle(new Request(
            [],
            $body,
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '203.0.113.10'],
        ));
    }

    /** POST without a CSRF token, to prove the guard actually rejects it. */
    protected function postWithoutToken(string $path, array $body = []): Response
    {
        return $this->app->handle(new Request(
            [],
            $body,
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $path, 'REMOTE_ADDR' => '203.0.113.10'],
        ));
    }

    /**
     * POST a JSON body with no session and no CSRF token — the shape a provider
     * webhook actually arrives in.
     *
     * @param array<string,mixed> $body
     */
    protected function postJson(string $path, array $body): Response
    {
        return $this->postRaw($path, (string) json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    /** POST a raw body, so a test can send something that is not valid JSON. */
    protected function postRaw(string $path, string $body, string $contentType = 'text/plain'): Response
    {
        return $this->app->handle(new Request(
            [],
            [],
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => $path,
                'REMOTE_ADDR'    => '203.0.113.10',
                'CONTENT_TYPE'   => $contentType,
            ],
            [],
            [],
            $body
        ));
    }

    /** @param array<string,mixed> $body */
    protected function apiRequest(string $method, string $path, string $apiKey, array $body = []): Response
    {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);

        return $this->app->handle(new Request(
            [],
            [],
            [
                'REQUEST_METHOD'      => $method,
                'REQUEST_URI'         => $path,
                'REMOTE_ADDR'         => '203.0.113.10',
                'HTTP_AUTHORIZATION'  => 'Bearer ' . $apiKey,
                'CONTENT_TYPE'        => 'application/json',
                'HTTP_ACCEPT'         => 'application/json',
            ],
            [],
            [],
            $encoded === false ? '' : $encoded
        ));
    }

    // ------------------------------------------------------------ assertions

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertions++;

        if (!$condition) {
            $this->fail($message !== '' ? $message : 'Expected true, got false.');
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertTrue(!$condition, $message !== '' ? $message : 'Expected false, got true.');
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected !== $actual) {
            $this->fail(sprintf(
                '%sExpected %s, got %s.',
                $message !== '' ? $message . ' — ' : '',
                $this->describe($expected),
                $this->describe($actual)
            ));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected != $actual) {
            $this->fail(sprintf(
                '%sExpected %s, got %s.',
                $message !== '' ? $message . ' — ' : '',
                $this->describe($expected),
                $this->describe($actual)
            ));
        }
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($unexpected === $actual) {
            $this->fail(sprintf(
                '%sDid not expect %s.',
                $message !== '' ? $message . ' — ' : '',
                $this->describe($actual)
            ));
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertTrue($value === null, $message !== '' ? $message : 'Expected null, got ' . $this->describe($value) . '.');
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->assertTrue($value !== null, $message !== '' ? $message : 'Expected a value, got null.');
    }

    protected function assertCount(int $expected, array $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message !== '' ? $message : 'Unexpected element count');
    }

    protected function assertContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (!str_contains($haystack, $needle)) {
            $this->fail(sprintf(
                '%sExpected to find "%s" in: %s',
                $message !== '' ? $message . ' — ' : '',
                $needle,
                mb_substr($haystack, 0, 400)
            ));
        }
    }

    protected function assertNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (str_contains($haystack, $needle)) {
            $this->fail(sprintf(
                '%sDid not expect to find "%s".',
                $message !== '' ? $message . ' — ' : '',
                $needle
            ));
        }
    }

    protected function assertStatus(int $expected, Response $response, string $message = ''): void
    {
        $this->assertSame($expected, $response->status(), $message !== '' ? $message : 'Unexpected HTTP status');
    }

    /** @param callable():void $callback */
    protected function assertThrows(string $expectedClass, callable $callback, string $message = ''): \Throwable
    {
        $this->assertions++;

        try {
            $callback();
        } catch (\Throwable $e) {
            if (!$e instanceof $expectedClass) {
                $this->fail(sprintf(
                    '%sExpected %s, got %s: %s',
                    $message !== '' ? $message . ' — ' : '',
                    $expectedClass,
                    $e::class,
                    $e->getMessage()
                ));
            }

            return $e;
        }

        $this->fail(sprintf(
            '%sExpected %s to be thrown, but nothing was.',
            $message !== '' ? $message . ' — ' : '',
            $expectedClass
        ));
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    private function describe(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        if (is_object($value)) {
            return $value::class;
        }

        return is_string($value) ? '"' . $value . '"' : (string) $value;
    }
}
