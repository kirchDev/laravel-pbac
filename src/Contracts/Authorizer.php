<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Contracts;

use KirchDev\Pbac\Decision\Decision;

interface Authorizer
{
    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public function inspect(mixed $actor, string $ability, array $arguments = []): ?Decision;
}
