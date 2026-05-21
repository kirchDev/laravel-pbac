<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Contracts;

interface Resettable
{
    public function reset(): void;
}
