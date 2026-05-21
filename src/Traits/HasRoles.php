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
        $role = $this->resolveRole($role);

        $this->roles()->syncWithoutDetaching([$role->getKey()]);
        $this->resetPbacDecisionCache();

        return $this;
    }

    public function removeRole(Role|string|int $role): static
    {
        $role = $this->resolveRole($role);

        $this->roles()->detach($role->getKey());
        $this->resetPbacDecisionCache();

        return $this;
    }

    public function hasRole(Role|string|int $role): bool
    {
        $role = $this->resolveRole($role);

        return $this->roles()->whereKey($role->getKey())->exists();
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
