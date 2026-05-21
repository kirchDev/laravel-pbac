<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use KirchDev\Pbac\Authorization\DecisionCache;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Models\RoleAssignment;
use KirchDev\Pbac\Queries\RolePermissionQuery;

/**
 * @template TRoleModel of Role = Role
 * @template TRoleAssignmentModel of RoleAssignment = RoleAssignment
 */
trait HasRoles
{
    /**
     * @return MorphToMany<TRoleModel, $this, TRoleAssignmentModel, 'pivot'>
     */
    public function roles(): MorphToMany
    {
        /** @var class-string<Role> $roleModel */
        $roleModel = config('pbac.models.role', Role::class);
        $table = config('pbac.table_names.model_has_roles', 'model_has_roles');
        $rolePivot = config('pbac.column_names.role_pivot_key') ?: 'role_id';
        $modelMorphKey = config('pbac.column_names.model_morph_key', 'model_id');
        /** @var class-string<RoleAssignment> $roleAssignmentModel */
        $roleAssignmentModel = config('pbac.models.role_assignment', RoleAssignment::class);

        return $this->morphToMany($roleModel, 'model', $table, $modelMorphKey, $rolePivot)
            ->using($roleAssignmentModel);
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return app(RolePermissionQuery::class)->untargetedPermissionNamesFor($this);
    }

    public function assignRole(Role|string|int $role): static
    {
        return $this->assignRoles($role);
    }

    public function assignRoles(Role|string|int ...$roles): static
    {
        if ($roles === []) {
            return $this;
        }

        $keys = $this->resolveRoleKeys($roles);

        $this->roles()->syncWithoutDetaching($keys);
        $this->resetPbacDecisionCache();

        return $this;
    }

    public function removeRole(Role|string|int $role): static
    {
        return $this->removeRoles($role);
    }

    public function removeRoles(Role|string|int ...$roles): static
    {
        if ($roles === []) {
            return $this;
        }

        $keys = $this->resolveRoleKeys($roles);

        $this->roles()->detach($keys);
        $this->resetPbacDecisionCache();

        return $this;
    }

    /**
     * @param  iterable<Role|string|int>  $roles
     */
    public function syncRoles(iterable $roles): static
    {
        $list = is_array($roles) ? $roles : iterator_to_array($roles, preserve_keys: false);

        $keys = $list === [] ? [] : $this->resolveRoleKeys($list);

        $this->roles()->sync($keys);
        $this->resetPbacDecisionCache();

        return $this;
    }

    public function hasRole(Role|string|int $role): bool
    {
        $role = $this->resolveRole($role);

        return $this->roles()->whereKey($role->getKey())->exists();
    }

    /**
     * @param  array<Role|string|int>  $roles
     * @return list<int|string>
     */
    private function resolveRoleKeys(array $roles): array
    {
        $keys = [];

        foreach ($roles as $role) {
            $keys[] = $this->resolveRole($role)->getKey();
        }

        return array_values(array_unique($keys, SORT_REGULAR));
    }

    private function resolveRole(Role|string|int $role): Role
    {
        if ($role instanceof Role) {
            return $role;
        }

        $roleModel = config('pbac.models.role', Role::class);

        if (is_int($role)) {
            return $roleModel::query()->findOrFail($role);
        }

        $organisationEnabled = (bool) config('pbac.organisation.enabled', false);
        $organisationForeignKey = config('pbac.column_names.organisation_foreign_key', 'organisation_id');
        $organisationId = app(OrganisationResolver::class)->getOrganisationId();

        return $roleModel::query()
            ->where('name', $role)
            ->when($organisationEnabled, function ($query) use ($organisationForeignKey, $organisationId) {
                $query->where($organisationForeignKey, $organisationId);
            })
            ->firstOrFail();
    }

    private function resetPbacDecisionCache(): void
    {
        app(DecisionCache::class)->reset();
    }
}
