<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Decision;

use KirchDev\Pbac\PbacManager;

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

    /**
     * Entries with context arrays stripped — step names preserved.
     *
     * @return list<array{step: string, context: array<string, mixed>}>
     */
    public function redacted(): array
    {
        return array_map(
            static fn (array $entry): array => ['step' => $entry['step'], 'context' => []],
            $this->entries,
        );
    }

    /**
     * Entries honouring the current redaction policy.
     *
     * @return list<array{step: string, context: array<string, mixed>}>
     */
    public function visible(): array
    {
        /** @var PbacManager $manager */
        $manager = app(PbacManager::class);

        return $manager->isTraceRedacted() ? $this->redacted() : $this->all();
    }

    /**
     * Compact, log-friendly string form. Honours redaction.
     */
    public function formatted(): string
    {
        $parts = [];

        foreach ($this->visible() as $entry) {
            $context = $entry['context'];
            $parts[] = $context === []
                ? $entry['step']
                : $entry['step'].'('.http_build_query($context, '', ', ').')';
        }

        return implode(' → ', $parts);
    }
}
