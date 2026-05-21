<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Decision;

final readonly class Decision
{
    private function __construct(
        private bool $allowed,
        private string $reason,
        private DecisionTrace $trace,
    ) {}

    public static function allow(string $reason = 'pbac.allowed', ?DecisionTrace $trace = null): self
    {
        return new self(true, $reason, $trace ?? new DecisionTrace);
    }

    public static function deny(string $reason = 'pbac.denied', ?DecisionTrace $trace = null): self
    {
        return new self(false, $reason, $trace ?? new DecisionTrace);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function trace(): DecisionTrace
    {
        return $this->trace;
    }
}
