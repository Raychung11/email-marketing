<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Heartbeat;
use Tests\Support\TestCase;

/**
 * The health check that told us cron was dead while cron was running fine.
 *
 * The original check read the modification time of the log the cron entry
 * redirects into. A scheduler tick with nothing to do writes no output, and a
 * production LOG_LEVEL discards what it does write, so the file never changed
 * and a healthy server reported a broken cron every time. A heartbeat is
 * written on purpose, on every run, regardless of either.
 */
final class HeartbeatTest extends TestCase
{
    private string $directory = '';

    private function heartbeat(): Heartbeat
    {
        if ($this->directory === '') {
            $this->directory = sys_get_temp_dir() . '/heartbeat-' . bin2hex(random_bytes(6));
        }

        return new Heartbeat($this->directory);
    }

    private function cleanUp(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
        $this->directory = '';
    }

    public function testARunWithNothingToDoStillRecordsThatItRan(): void
    {
        $heartbeat = $this->heartbeat();

        // No context at all: the quiet tick is exactly the case the old check
        // could not see.
        $heartbeat->record('scheduler');

        $age = $heartbeat->secondsSince('scheduler');

        $this->assertNotNull($age, 'A quiet run is still a run');
        $this->assertTrue($age < 5, 'And it reads as having happened just now');

        $this->cleanUp();
    }

    public function testSomethingThatHasNeverRunIsDistinguishableFromSomethingStale(): void
    {
        $heartbeat = $this->heartbeat();

        // null means "never", not "a very long time ago" — the two deserve
        // different advice, since one of them means the cron job was never added.
        $this->assertNull($heartbeat->secondsSince('worker'));
        $this->assertNull($heartbeat->lastRun('worker'));

        $this->cleanUp();
    }

    public function testAStaleHeartbeatReportsItsRealAge(): void
    {
        $heartbeat = $this->heartbeat();
        $heartbeat->record('scheduler');

        $twoHours = $heartbeat->secondsSince('scheduler', time() + 7200);

        $this->assertTrue($twoHours !== null && $twoHours >= 7200, 'Age is measured against the recorded time');

        $this->cleanUp();
    }

    public function testATruncatedHeartbeatStillProvesTheProcessWasThere(): void
    {
        $heartbeat = $this->heartbeat();
        $heartbeat->record('scheduler');

        // A run killed mid-write leaves unparseable JSON. That is still evidence
        // the process ran, and claiming "never run" would send someone hunting a
        // cron job that is working.
        file_put_contents($this->directory . '/scheduler.heartbeat', '{"at":"2026-');

        $this->assertNotNull($heartbeat->lastRun('scheduler'), 'Falls back to the file timestamp');

        $this->cleanUp();
    }

    public function testAHeartbeatNameCannotWriteOutsideItsDirectory(): void
    {
        $heartbeat = $this->heartbeat();
        $heartbeat->record('../../escaped');

        $this->assertFalse(
            is_file(dirname($this->directory, 2) . '/escaped.heartbeat'),
            'Path separators are stripped rather than followed'
        );

        $this->cleanUp();
    }

    public function testAnUnwritableDirectoryCostsTheCheckAndNotTheSend(): void
    {
        // The heartbeat is instrumentation. If it cannot be written, the campaign
        // still goes out — an exception here would take down the thing it measures.
        $heartbeat = new Heartbeat('/proc/nonexistent/heartbeats');

        $heartbeat->record('scheduler');

        $this->assertNull($heartbeat->secondsSince('scheduler'), 'It simply has nothing to report');
    }
}
