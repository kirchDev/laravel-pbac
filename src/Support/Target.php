<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Support;

use Illuminate\Database\Eloquent\Model;

final class Target
{
    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public static function fromArguments(array $arguments): ?ModelIdentifier
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Model) {
                return ModelIdentifier::fromModel($argument);
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public static function modelFromArguments(array $arguments): ?Model
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof Model) {
                return $argument;
            }
        }

        return null;
    }
}
