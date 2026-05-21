<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Authorization;

use Illuminate\Database\Eloquent\Model;
use KirchDev\Pbac\Contracts\Resettable;
use KirchDev\Pbac\Models\Permission;

/**
 * Request-scoped lookup for {@see Permission} models by name.
 *
 * Hot path: when `pbac.gate.manage_existing_permissions_only=true` is set, every
 * `$user->can(...)` for a native Laravel gate would otherwise hit the database for
 * a permission row that does not exist. This cache keeps both positive and
 * negative results in memory for the duration of the request.
 */
final class PermissionResolver implements Resettable
{
    /**
     * @var array<string, Model|null>
     */
    private array $resolved = [];

    public function resolve(string $ability): ?Model
    {
        if (array_key_exists($ability, $this->resolved)) {
            return $this->resolved[$ability];
        }

        /** @var class-string<Model> $permissionModel */
        $permissionModel = config('pbac.models.permission');

        return $this->resolved[$ability] = $permissionModel::query()
            ->where('name', $ability)
            ->first();
    }

    /**
     * Forget a single ability's cached entry.
     */
    public function forget(string $ability): void
    {
        unset($this->resolved[$ability]);
    }

    public function flush(): void
    {
        $this->resolved = [];
    }

    public function reset(): void
    {
        $this->flush();
    }
}
