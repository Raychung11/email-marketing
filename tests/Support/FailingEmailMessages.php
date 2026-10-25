<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Clock;
use App\Database\Connection;
use App\Repositories\EmailMessageRepository;
use App\Support\TenantContext;
use RuntimeException;

/**
 * An email_messages repository that fails on command.
 *
 * Two moments matter and they are not the same.
 *
 * Failing on `create` is a database problem BEFORE the provider has been asked
 * to do anything: nothing was sent, and the recipient is safe to retry.
 *
 * Failing on `update` is the nastiest moment in the whole pipeline — the
 * provider has accepted the message, the email is on its way, and the write
 * that records that does not land. A connection dropped, a disk filled, a lock
 * timed out. The email cannot be unsent, so the only wrong answer is to send it
 * again.
 */
final class FailingEmailMessages extends EmailMessageRepository
{
    public function __construct(
        Connection $connection,
        TenantContext $tenant,
        Clock $clock,
        private readonly string $failOn = 'update',
    ) {
        parent::__construct($connection, $tenant, $clock);
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): int
    {
        if ($this->failOn === 'create') {
            throw new RuntimeException('Lost connection to MySQL server during query');
        }

        return parent::create($attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function update(int $id, array $attributes): int
    {
        if ($this->failOn === 'update') {
            throw new RuntimeException('Lost connection to MySQL server during query');
        }

        return parent::update($id, $attributes);
    }
}
