<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Decision;

final class DecisionTrace
{
    /**
     * @var list<array{step: string, context: array<string, mixed>}>
     */
    private array $entries = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function add(string $step, array $context = []): self
    {
        $this->entries[] = [
            'step' => $step,
            'context' => $context,
        ];

        return $this;
    }

    /**
     * @return list<array{step: string, context: array<string, mixed>}>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
