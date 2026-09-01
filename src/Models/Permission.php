<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use KirchDev\Pbac\Authorization\DecisionCache;

/**
 * @template TRoleModel of Role = Role
 * @template TRolePermissionModel of RolePermission = RolePermission
 */
class Permission extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];

    protected static function booted(): void
    {
        static::saved(fn (): null => self::resetPbacDecisionCache());
        static::deleted(fn (): null => self::resetPbacDecisionCache());
    }

    public function getTable(): string
    {
        return config('pbac.table_names.permissions', 'permissions');
    }

    /**
     * @return BelongsToMany<TRoleModel, $this, TRolePermissionModel, 'pivot'>
     */
    public function roles(): BelongsToMany
    {
        /** @var class-string<TRoleModel> $roleModel */
        $roleModel = config('pbac.models.role', Role::class);
        $table = config('pbac.table_names.role_has_permissions', 'role_has_permissions');
        $rolePivot = config('pbac.column_names.role_pivot_key') ?: 'role_id';
        $permissionPivot = config('pbac.column_names.permission_pivot_key') ?: 'permission_id';
        $targetMorphKey = config('pbac.column_names.target_morph_key', 'target_id');
        /** @var class-string<TRolePermissionModel> $rolePermissionModel */
        $rolePermissionModel = config('pbac.models.role_permission', RolePermission::class);

        return $this->belongsToMany($roleModel, $table, $permissionPivot, $rolePivot)
            ->using($rolePermissionModel)
            ->withPivot(['target_type', $targetMorphKey]);
    }

    public static function findOrCreate(string $name): static
    {
        /** @var static $permission */
        $permission = static::query()->firstOrCreate([
            'name' => $name,
        ]);

        return $permission;
    }

    public static function findByName(string $name): static
    {
        /** @var static $permission */
        $permission = static::query()
            ->where('name', $name)
            ->firstOrFail();

        return $permission;
    }

    private static function resetPbacDecisionCache(): null
    {
        app(DecisionCache::class)->reset();

        return null;
    }
}
