<?php

declare(strict_types=1);

namespace App\Support;

/**
 * "Did this actually run, and when?"
 *
 * Deliberately a file the process writes on purpose, rather than something
 * inferred from a log. Inference does not survive contact with production: a
 * scheduler that has nothing to do writes nothing, and on a normal
 * LOG_LEVEL=warning install writes nothing either way — so a log's modification
 * time reports "cron is dead" on a perfectly healthy server. Somebody then goes
 * looking for a broken cron job that does not exist.
 *
 * A heartbeat is written whether or not there was work, and whether or not the
 * log level would have recorded any of it.
 */
final class Heartbeat
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string,mixed> $context */
    public function record(string $name, array $context = []): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $payload = json_encode(
            ['at' => gmdate('c'), 'unix' => time()] + $context,
            JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            return;
        }

        // A heartbeat that throws would take down the thing it is measuring, so
        // every failure here is swallowed: an unwritable directory should cost
        // you the health check, not the campaign send.
        @file_put_contents($this->path($name), $payload . PHP_EOL, LOCK_EX);
    }

    /** Unix timestamp of the last recorded run, or null if it has never run. */
    public function lastRun(string $name): ?int
    {
        $path = $this->path($name);

        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (is_array($decoded) && isset($decoded['unix']) && is_numeric($decoded['unix'])) {
            return (int) $decoded['unix'];
        }

        // A truncated or half-written file still tells us the process was here.
        $modified = @filemtime($path);

        return $modified === false ? null : $modified;
    }

    /** Seconds since the last run, or null if it has never run. */
    public function secondsSince(string $name, ?int $now = null): ?int
    {
        $last = $this->lastRun($name);

        return $last === null ? null : max(0, ($now ?? time()) - $last);
    }

    private function path(string $name): string
    {
        // The name comes from our own call sites, never from a request, but a
        // path separator sneaking in here would write outside the directory.
        return $this->directory . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $name) . '.heartbeat';
    }
}
