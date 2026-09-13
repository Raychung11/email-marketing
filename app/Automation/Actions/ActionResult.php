<?php

declare(strict_types=1);

namespace App\Automation\Actions;

/**
 * What happened when a journey step ran.
 *
 * `outcome` matches the vocabulary of automation_run_logs, so every step is
 * explainable after the fact — which matters most for the ones that did
 * nothing, because "why did my customer not get that email" is the question
 * somebody will actually ask.
 */
final class ActionResult
{
    /** @param array<string,mixed> $metadata */
    private function __construct(
        public readonly string $outcome,
        public readonly string $message = '',
        public readonly ?string $reasonCode = null,
        public readonly array $metadata = [],
    ) {
    }

    /** @param array<string,mixed> $metadata */
    public static function done(string $message = '', array $metadata = []): self
    {
        return new self('passed', $message, null, $metadata);
    }

    /** Nothing to do, and that is fine — an already-present tag, say. */
    public static function skipped(string $message, ?string $reasonCode = null): self
    {
        return new self('skipped', $message, $reasonCode);
    }

    /**
     * Deliberately refused: suppression, consent, a missing address.
     *
     * Recorded rather than swallowed, because a silent non-send is
     * indistinguishable from a bug.
     */
    public static function blocked(string $reasonCode, string $message): self
    {
        return new self('blocked', $message, $reasonCode);
    }

    public static function failed(string $message): self
    {
        return new self('error', $message);
    }

    public function ok(): bool
    {
        return $this->outcome !== 'error';
    }
}
