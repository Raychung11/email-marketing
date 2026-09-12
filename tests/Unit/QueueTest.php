<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Queue\DatabaseQueueDriver;
use App\Queue\Job;
use App\Queue\QueueManager;
use Tests\Support\TestCase;

/**
 * §64 — retry backoff, permanent failures and the dead-letter table.
 */
final class QueueTest extends TestCase
{
    public function testBackoffFollowsTheConfiguredSchedule(): void
    {
        /** @var QueueManager $queue */
        $queue = $this->container->make(QueueManager::class);

        $this->assertSame(60, $queue->backoffFor(0), 'First retry after a minute');
        $this->assertSame(300, $queue->backoffFor(1), 'Then five minutes');
        $this->assertSame(1800, $queue->backoffFor(2), 'Then thirty minutes');
        $this->assertSame(7200, $queue->backoffFor(3), 'Then two hours');
        $this->assertNull($queue->backoffFor(4), 'Then it is dead-lettered rather than retried for ever');
    }

    public function testAJobIsReservedByExactlyOneWorker(): void
    {
        $driver = $this->driver();

        $driver->push(new Job('email_marketing', 'App\Jobs\SendCampaignEmail', ['recipient_id' => 1]));

        $first  = $driver->pop('email_marketing', 'worker-a');
        $second = $driver->pop('email_marketing', 'worker-b');

        $this->assertNotNull($first, 'The first worker claims the job');
        $this->assertNull($second, 'A second worker sees nothing: the claim is atomic');
    }

    public function testADelayedJobIsNotVisibleUntilItIsDue(): void
    {
        $driver = $this->driver();

        $driver->push(new Job('email_retry', 'App\Jobs\SendCampaignEmail', []), 300);

        $this->assertNull($driver->pop('email_retry', 'worker-a'), 'Not yet');

        $this->clock->travel('+6 minutes');

        $this->assertNotNull($driver->pop('email_retry', 'worker-a'), 'Now it is due');
    }

    public function testAReleasedJobCarriesItsAttemptCount(): void
    {
        $driver = $this->driver();

        $driver->push(new Job('email_marketing', 'App\Jobs\SendCampaignEmail', ['recipient_id' => 7]));

        $job = $driver->pop('email_marketing', 'worker-a');
        $this->assertNotNull($job);
        $this->assertSame(0, $job->attemptCount);

        $driver->release($job->withAttempt(1), 60);

        $this->clock->travel('+2 minutes');

        $retried = $driver->pop('email_marketing', 'worker-a');

        $this->assertNotNull($retried);
        $this->assertSame(1, $retried->attemptCount, 'The attempt count survives the retry');
        $this->assertSame(7, $retried->payload['recipient_id'], 'And so does the payload');
    }

    public function testAFailedJobIsDeadLetteredWithItsContext(): void
    {
        $driver = $this->driver();

        $org = $this->createOrganisation();

        $driver->push(new Job(
            'email_marketing',
            'App\Jobs\SendCampaignEmail',
            ['recipient_id' => 99],
            $org['organisation_id']
        ));

        $job = $driver->pop('email_marketing', 'worker-a');
        $driver->fail($job, 'Recipient is suppressed', true);

        $failed = $this->connection->selectOne('SELECT * FROM failed_jobs ORDER BY id DESC');

        $this->assertNotNull($failed);
        $this->assertSame('email_marketing', (string) $failed['queue']);
        $this->assertSame(1, (int) $failed['permanent'], 'A permanent failure is marked as such, not retried');
        $this->assertContainsString('suppressed', (string) $failed['exception']);
        $this->assertContainsString('99', (string) $failed['payload'], 'The payload is kept so it can be replayed');

        $this->assertSame(0, $driver->size('email_marketing'), 'And it leaves the live queue');
    }

    public function testAStalledJobIsReclaimedAfterTheVisibilityTimeout(): void
    {
        $driver = $this->driver();

        $driver->push(new Job('email_marketing', 'App\Jobs\SendCampaignEmail', []));

        $claimed = $driver->pop('email_marketing', 'worker-a');
        $this->assertNotNull($claimed);

        // The worker dies without acknowledging.
        $this->assertNull($driver->pop('email_marketing', 'worker-b'), 'Still held by worker-a');

        $this->clock->travel('+11 minutes');

        $this->assertNotNull(
            $driver->pop('email_marketing', 'worker-b'),
            'After the visibility timeout another worker picks it up, so a crash does not lose the job'
        );
    }

    public function testQueueDepthIsReportable(): void
    {
        $driver = $this->driver();

        for ($i = 0; $i < 4; $i++) {
            $driver->push(new Job('email_marketing', 'App\Jobs\SendCampaignEmail', ['n' => $i]));
        }

        $driver->push(new Job('email_transactional', 'App\Jobs\SendTransactionalEmail', []));

        $this->assertSame(4, $driver->size('email_marketing'));
        $this->assertSame(1, $driver->size('email_transactional'));
    }

    private function driver(): DatabaseQueueDriver
    {
        return new DatabaseQueueDriver($this->connection, $this->clock);
    }
}
