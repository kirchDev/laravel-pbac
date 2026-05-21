<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class ModelIdentifier
{
    private function __construct(
        public string $type,
        public int|string $id,
    ) {}

    public static function fromModel(Model $model): self
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('Target models must be persisted before they can be used for PBAC authorization.');
        }

        return new self($model->getMorphClass(), $key);
    }
}
