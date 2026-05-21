<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Authorization;

use KirchDev\Pbac\Contracts\Resettable;
use KirchDev\Pbac\Decision\Decision;

final class DecisionCache implements Resettable
{
    /**
     * @var array<string, Decision|null>
     */
    private array $items = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key): ?Decision
    {
        return $this->items[$key] ?? null;
    }

    public function put(string $key, ?Decision $decision): void
    {
        $this->items[$key] = $decision;
    }

    public function reset(): void
    {
        $this->items = [];
    }
}
