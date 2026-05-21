<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KirchDev\Pbac\Authorization\DecisionCache;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Support\ModelIdentifier;

class Role extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'organisation_id',
    ];

    protected static function booted(): void
    {
        static::saved(fn (): null => self::flushPbacDecisionCache());
        static::deleted(fn (): null => self::flushPbacDecisionCache());
    }

    public function getTable(): string
    {
        return config('pbac.table_names.roles', 'roles');
    }

    public function isFillable($key): bool
    {
        if ($key === config('pbac.column_names.organisation_foreign_key', 'organisation_id')) {
            return true;
        }

        return parent::isFillable($key);
    }

    /**
     * @return BelongsToMany<Permission, $this, RolePermission, 'pivot'>
     */
    public function permissions(): BelongsToMany
    {
        /** @var class-string<Permission> $permissionModel */
        $permissionModel = config('pbac.models.permission', Permission::class);
        $table = config('pbac.table_names.role_has_permissions', 'role_has_permissions');
        $rolePivot = config('pbac.column_names.role_pivot_key') ?: 'role_id';
        $permissionPivot = config('pbac.column_names.permission_pivot_key') ?: 'permission_id';
        $targetMorphKey = config('pbac.column_names.target_morph_key', 'target_id');
        /** @var class-string<RolePermission> $rolePermissionModel */
        $rolePermissionModel = config('pbac.models.role_permission', RolePermission::class);

        return $this->belongsToMany($permissionModel, $table, $rolePivot, $permissionPivot)
            ->using($rolePermissionModel)
            ->withPivot(['target_type', $targetMorphKey]);
    }

    public static function findOrCreate(string $name, int|string|null $organisationId = null): static
    {
        $organisationEnabled = (bool) config('pbac.organisation.enabled', false);
        $organisationForeignKey = config('pbac.column_names.organisation_foreign_key', 'organisation_id');

        $attributes = [
            'name' => $name,
        ];

        if ($organisationEnabled) {
            $attributes[$organisationForeignKey] = $organisationId;
        }

        /** @var static $role */
        $role = static::query()->firstOrCreate($attributes);

        return $role;
    }

    public static function findByName(string $name, int|string|null $organisationId = null): static
    {
        $organisationEnabled = (bool) config('pbac.organisation.enabled', false);
        $organisationForeignKey = config('pbac.column_names.organisation_foreign_key', 'organisation_id');

        $query = static::query()->where('name', $name);

        if ($organisationEnabled) {
            $query->where($organisationForeignKey, $organisationId);
        }

        /** @var static $role */
        $role = $query->firstOrFail();

        return $role;
    }

    public function givePermissionTo(Permission|string $permission, ?Model $target = null): static
    {
        $permission = $this->resolvePermission($permission);
        $target = $target ? ModelIdentifier::fromModel($target) : null;

        $this->assertPersisted();

        $table = config('pbac.table_names.role_has_permissions', 'role_has_permissions');
        $rolePivot = config('pbac.column_names.role_pivot_key') ?: 'role_id';
        $permissionPivot = config('pbac.column_names.permission_pivot_key') ?: 'permission_id';
        $targetMorphKey = config('pbac.column_names.target_morph_key', 'target_id');

        DB::table($table)->updateOrInsert([
            $rolePivot => $this->getKey(),
            $permissionPivot => $permission->getKey(),
            'target_type' => $target?->type,
            $targetMorphKey => $target?->id,
        ]);

        $this->resetPbacDecisionCache();

        return $this;
    }

    public function revokePermissionTo(Permission|string $permission, ?Model $target = null): static
    {
        $permission = $this->resolvePermission($permission);
        $target = $target ? ModelIdentifier::fromModel($target) : null;

        $this->assertPersisted();

        $table = config('pbac.table_names.role_has_permissions', 'role_has_permissions');
        $rolePivot = config('pbac.column_names.role_pivot_key') ?: 'role_id';
        $permissionPivot = config('pbac.column_names.permission_pivot_key') ?: 'permission_id';
        $targetMorphKey = config('pbac.column_names.target_morph_key', 'target_id');

        DB::table($table)
            ->where($rolePivot, $this->getKey())
            ->where($permissionPivot, $permission->getKey())
            ->where('target_type', $target?->type)
            ->where($targetMorphKey, $target?->id)
            ->delete();

        $this->resetPbacDecisionCache();

        return $this;
    }

    public function hasPermissionTo(Permission|string $permission, ?Model $target = null): bool
    {
        $permission = $this->resolvePermission($permission);
        $target = $target ? ModelIdentifier::fromModel($target) : null;

        $this->assertPersisted();

        $table = config('pbac.table_names.role_has_permissions', 'role_has_permissions');
        $rolePivot = config('pbac.column_names.role_pivot_key') ?: 'role_id';
        $permissionPivot = config('pbac.column_names.permission_pivot_key') ?: 'permission_id';
        $targetMorphKey = config('pbac.column_names.target_morph_key', 'target_id');

        return DB::table($table)
            ->where($rolePivot, $this->getKey())
            ->where($permissionPivot, $permission->getKey())
            ->where('target_type', $target?->type)
            ->where($targetMorphKey, $target?->id)
            ->exists();
    }

    private function resolvePermission(Permission|string $permission): Permission
    {
        if ($permission instanceof Permission) {
            return $permission;
        }

        $permissionModel = config('pbac.models.permission', Permission::class);

        return $permissionModel::findOrCreate($permission);
    }

    private function assertPersisted(): void
    {
        if ($this->getKey() === null) {
            throw new InvalidArgumentException('A role must be persisted before permissions can be granted.');
        }
    }

    public function setCurrentOrganisation(): void
    {
        if (! (bool) config('pbac.organisation.enabled', false)) {
            return;
        }

        app(OrganisationResolver::class)->setOrganisationId($this->getAttribute(config('pbac.column_names.organisation_foreign_key', 'organisation_id')));
        app(DecisionCache::class)->reset();
    }

    private function resetPbacDecisionCache(): void
    {
        self::flushPbacDecisionCache();
    }

    private static function flushPbacDecisionCache(): null
    {
        app(DecisionCache::class)->reset();

        return null;
    }
}
