<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migration;
use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

/**
 * Rate-limit counters that survive the request that made them.
 *
 * WHAT WAS WRONG.
 *
 * The store was chosen from `queue.driver`. Anything other than Redis — which
 * on Hostinger means `database`, the only queue driver shared hosting can run —
 * fell through to ArrayRateLimitStore, a PHP array. Every HTTP request is a
 * fresh process, so every request started with an empty array: login throttling
 * counted to one and reset, the API key limit never bound, and the public
 * endpoints could be hit as fast as a client could open connections. The
 * protection existed in code and did nothing in production.
 *
 * WHY A TABLE RATHER THAN A FILE OR APCU.
 *
 * Shared hosting gives no shared memory between PHP processes worth relying on:
 * APCu is per-process-pool and empty after a restart, and a file needs its own
 * locking to be atomic. The database is already there, already shared by every
 * request, and already has the one primitive this needs — an atomic upsert.
 *
 * The rows are tiny, short-lived and swept. One row per key per window.
 */
return new class extends Migration {
    public function up(Schema $schema, Connection $connection): void
    {
        $schema->create('rate_limits', static function (Blueprint $table): void {
            $table->id();

            /*
             * Not tenant-scoped, deliberately. The two things this protects
             * most — a login attempt and an unauthenticated public endpoint —
             * happen before anybody knows which organisation is involved, and a
             * limiter that needs a tenant to work is a limiter that is off for
             * exactly the requests that matter. The key carries whatever scope
             * applies: an account, an API key, a route and an IP.
             *
             * `limit_key`, not `key`: `key` is reserved in MySQL.
             */
            $table->string('limit_key', 190);
            $table->integer('hits')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('created_at');

            // The upsert's conflict target. Without it two concurrent requests
            // insert two rows for one key and each counts half the traffic.
            $table->unique('limit_key');
            // Sweeping expired windows is the only scan this table ever takes.
            $table->index('expires_at');
        });
    }

    public function down(Schema $schema, Connection $connection): void
    {
        $schema->drop('rate_limits');
    }
};
