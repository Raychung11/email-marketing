<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Core\ArrayRateLimitStore;
use App\Core\Clock;
use App\Core\DatabaseRateLimitStore;
use App\Core\RateLimiter;
use App\Core\RateLimitStore;
use Tests\Support\TestCase;

/**
 * Rate limiting that outlives a request.
 *
 * The original wiring chose the store from the queue driver, so any deployment
 * on the database queue — which is every deployment on shared hosting, where
 * there is no Redis — got the in-process store. On shared hosting each request
 * is a fresh PHP process, so that store counted to one and forgot. Login
 * throttling was not weak; it was absent, and it looked fine from inside a
 * single test run because the array store works perfectly well as long as you
 * never leave the process.
 *
 * So the tests here deliberately cross process boundaries: two stores, two
 * limiters, two application instances, sharing nothing but the database.
 */
final class RateLimitPersistenceTest extends TestCase
{
    /** The thing the array store cannot do: be seen by somebody else. */
    public function testTwoSeparateLimitersShareTheSameCounter(): void
    {
        $first  = $this->freshStore();
        $second = $this->freshStore();

        $this->assertSame(1, $first->increment('login:someone@example.com', 900));
        $this->assertSame(2, $second->increment('login:someone@example.com', 900));
        $this->assertSame(3, $first->increment('login:someone@example.com', 900));

        $this->assertSame(3, $second->get('login:someone@example.com'), 'both see the same count');
    }

    /**
     * The same, through the limiter the application actually calls, so the
     * assertion is about the behaviour a login route depends on rather than
     * about the store's interface.
     */
    public function testLockoutHoldsAcrossRequests(): void
    {
        $key = 'login:' . bin2hex(random_bytes(4)) . '@example.com';

        for ($request = 0; $request < 5; $request++) {
            $limiter = new RateLimiter($this->freshStore());

            $this->assertFalse(
                $limiter->tooManyAttempts($key, 5),
                'attempt ' . ($request + 1) . ' is still within the allowance'
            );

            $limiter->hit($key, 900);
        }

        // A sixth request, in a sixth process.
        $this->assertTrue(
            (new RateLimiter($this->freshStore()))->tooManyAttempts($key, 5),
            'the sixth request is refused, which is the whole point'
        );
    }

    /** A real application instance, not a hand-built store. */
    public function testTwoApplicationInstancesShareTheirCounters(): void
    {
        $limiter = $this->container->make(RateLimiter::class);
        $store   = $this->container->make(RateLimitStore::class);

        $this->assertTrue(
            $store instanceof DatabaseRateLimitStore,
            'the suite runs the store production runs, not the in-process one'
        );

        $limiter->hit('api:abcd1234', 60);
        $limiter->hit('api:abcd1234', 60);

        // A second limiter resolved from the same container is what a second
        // request would get. Its counter is in the database, not in its memory.
        $second = new RateLimiter($this->freshStore());

        $this->assertSame(2, $second->attempts('api:abcd1234'));
    }

    // -------------------------------------------------------------- windows

    public function testTheWindowExpiresAndTheCountStartsAgain(): void
    {
        $store = $this->freshStore();

        $store->increment('public:unsubscribe:203.0.113.4', 60);
        $store->increment('public:unsubscribe:203.0.113.4', 60);

        $this->assertSame(2, $store->get('public:unsubscribe:203.0.113.4'));
        $this->assertSame(60, $store->ttl('public:unsubscribe:203.0.113.4'));

        $this->clock->travel('+61 seconds');

        $this->assertSame(0, $store->get('public:unsubscribe:203.0.113.4'), 'the window lapsed');
        $this->assertSame(0, $store->ttl('public:unsubscribe:203.0.113.4'));
        $this->assertSame(1, $store->increment('public:unsubscribe:203.0.113.4', 60), 'and counting starts again');
    }

    /**
     * A lapsed bucket is restarted in place. Deleting and reinserting would
     * race on the unique index, and the loser would either throw or silently
     * drop its hit — and a dropped hit is a free attempt for an attacker.
     */
    public function testRestartingALapsedWindowDoesNotLoseConcurrentHits(): void
    {
        $a = $this->freshStore();
        $b = $this->freshStore();

        $a->increment('login:racer@example.com', 60);
        $this->clock->travel('+61 seconds');

        $first  = $a->increment('login:racer@example.com', 60);
        $second = $b->increment('login:racer@example.com', 60);

        $this->assertSame(1, $first, 'the first one through restarts the window');
        $this->assertSame(2, $second, 'and the second is counted rather than lost');
    }

    // ------------------------------------------------------------ isolation

    public function testCountersAreIsolatedByWhatIsBeingLimited(): void
    {
        $store = $this->freshStore();

        $store->increment('login:one@example.com', 900);
        $store->increment('login:one@example.com', 900);
        $store->increment('login:two@example.com', 900);
        $store->increment('api:key-aaaa', 60);
        $store->increment('public:form:198.51.100.7', 60);

        $this->assertSame(2, $store->get('login:one@example.com'));
        $this->assertSame(1, $store->get('login:two@example.com'), 'one account cannot lock another out');
        $this->assertSame(1, $store->get('api:key-aaaa'));
        $this->assertSame(1, $store->get('public:form:198.51.100.7'));
        $this->assertSame(0, $store->get('login:three@example.com'));
    }

    /**
     * What is being throttled is frequently an email address or an IP. Storing
     * those would build a second log of who tried to sign in and from where,
     * which a throttle has no business creating.
     */
    public function testTheStoredKeyDoesNotRevealWhoIsBeingLimited(): void
    {
        $store = $this->freshStore();
        $store->increment('login:victim@example.com', 900);

        $rows = $this->connection->select('SELECT bucket FROM rate_limits');

        $this->assertCount(1, $rows);
        $this->assertNotContainsString('victim@example.com', (string) $rows[0]['bucket']);
        $this->assertNotContainsString('@', (string) $rows[0]['bucket']);
    }

    public function testLapsedBucketsArePrunedSoTheTableDoesNotGrowForever(): void
    {
        $store = $this->freshStore();

        $store->increment('login:a@example.com', 60);
        $store->increment('login:b@example.com', 3600);

        $this->clock->travel('+61 seconds');

        $this->assertSame(1, $store->prune());
        $this->assertSame(
            1,
            (int) $this->connection->scalar('SELECT COUNT(*) FROM rate_limits'),
            'the live bucket is left alone'
        );
    }

    /**
     * The array store is still the right answer in a unit test; it is only
     * wrong as a silent production fallback. Keeping it honest here guards the
     * interface rather than the wiring.
     */
    public function testTheInProcessStoreStillBehavesForUnitTests(): void
    {
        $store = new ArrayRateLimitStore($this->container->make(Clock::class));

        $this->assertSame(1, $store->increment('k', 60));
        $this->assertSame(2, $store->increment('k', 60));
        $store->forget('k');
        $this->assertSame(0, $store->get('k'));
    }

    /** A store built the way a separate request would build one. */
    private function freshStore(): DatabaseRateLimitStore
    {
        return new DatabaseRateLimitStore($this->connection, $this->container->make(Clock::class));
    }
}
