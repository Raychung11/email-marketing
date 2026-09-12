<?php

declare(strict_types=1);

namespace App\Compliance;

/**
 * The result of a compliance evaluation.
 *
 * Deliberately a value object rather than a bare bool: the reason code is stored
 * on the recipient snapshot, the applied rules make a historical send
 * explainable, and the message is what the UI shows the user.
 */
final class ComplianceDecision implements \JsonSerializable
{
    /** @param array<int,string> $rulesApplied */
    private function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly array $rulesApplied = [],
        public readonly string $severity = 'block',
        public readonly string $message = '',
    ) {
    }

    /** @param array<int,string> $rulesApplied */
    public static function allow(array $rulesApplied = []): self
    {
        return new self(true, ReasonCode::ALLOWED, $rulesApplied, 'none', ReasonCode::describe(ReasonCode::ALLOWED));
    }

    /** @param array<int,string> $rulesApplied */
    public static function block(string $reason, array $rulesApplied = [], string $message = ''): self
    {
        return new self(
            false,
            $reason,
            $rulesApplied,
            'block',
            $message !== '' ? $message : ReasonCode::describe($reason)
        );
    }

    /**
     * A non-blocking finding: the send may proceed but the user should see it.
     *
     * @param array<int,string> $rulesApplied
     */
    public static function warn(string $reason, array $rulesApplied = [], string $message = ''): self
    {
        return new self(
            true,
            $reason,
            $rulesApplied,
            'warning',
            $message !== '' ? $message : ReasonCode::describe($reason)
        );
    }

    public function blocked(): bool
    {
        return !$this->allowed;
    }

    /** @return array{allowed:bool,reason:string,rules_applied:array<int,string>,severity:string,message:string} */
    public function toArray(): array
    {
        return [
            'allowed'       => $this->allowed,
            'reason'        => $this->reason,
            'rules_applied' => $this->rulesApplied,
            'severity'      => $this->severity,
            'message'       => $this->message,
        ];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
