<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Traits;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;
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

    public function assignRole(Role|string|int $role, bool $global = false): static
    {
        return $this->assignRoles($role, global: $global);
    }

    /**
     * @param  Role|string|int  ...$roles
     */
    public function assignRoles(Role|string|int|bool ...$roles): static
    {
        [$roles, $global] = $this->extractGlobalFlag($roles);

        if ($roles === []) {
            return $this;
        }

        $keys = $this->resolveRoleKeys($roles, $global);

        $this->roles()->syncWithoutDetaching($keys);
        $this->resetPbacDecisionCache();

        return $this;
    }

    public function removeRole(Role|string|int $role, bool $global = false): static
    {
        return $this->removeRoles($role, global: $global);
    }

    /**
     * @param  Role|string|int  ...$roles
     */
    public function removeRoles(Role|string|int|bool ...$roles): static
    {
        [$roles, $global] = $this->extractGlobalFlag($roles);

        if ($roles === []) {
            return $this;
        }

        $keys = $this->resolveRoleKeys($roles, $global);

        $this->roles()->detach($keys);
        $this->resetPbacDecisionCache();

        return $this;
    }

    /**
     * @param  iterable<Role|string|int>  $roles
     */
    public function syncRoles(iterable $roles, bool $global = false): static
    {
        $list = is_array($roles) ? $roles : iterator_to_array($roles, preserve_keys: false);

        $keys = $list === [] ? [] : $this->resolveRoleKeys($list, $global);

        $this->roles()->sync($keys);
        $this->resetPbacDecisionCache();

        return $this;
    }

    public function hasRole(Role|string|int $role, bool $global = false): bool
    {
        try {
            $role = $this->resolveRole($role, $global);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $this->roles()->whereKey($role->getKey())->exists();
    }

    /**
     * Extract a trailing named-style `global: true` argument from the variadic role list.
     *
     * The variadic API needs to keep accepting plain Role/string/int values while also
     * supporting `assignRoles(...$roles, global: true)`. PHP places the named arg at the
     * end of the variadic array; we peel it off here.
     *
     * @param  array<Role|string|int|bool>  $roles
     * @return array{0: array<Role|string|int>, 1: bool}
     */
    private function extractGlobalFlag(array $roles): array
    {
        if (array_key_exists('global', $roles)) {
            $flag = (bool) $roles['global'];
            unset($roles['global']);

            /** @var array<Role|string|int> $roles */
            return [array_values($roles), $flag];
        }

        /** @var array<Role|string|int> $roles */
        return [$roles, false];
    }

    /**
     * @param  array<Role|string|int>  $roles
     * @return list<int|string>
     */
    private function resolveRoleKeys(array $roles, bool $global = false): array
    {
        $keys = [];

        foreach ($roles as $role) {
            $keys[] = $this->resolveRole($role, $global)->getKey();
        }

        return array_values(array_unique($keys, SORT_REGULAR));
    }

    private function resolveRole(Role|string|int $role, bool $global = false): Role
    {
        if ($role instanceof Role) {
            return $role;
        }

        /** @var class-string<Role> $roleModel */
        $roleModel = config('pbac.models.role', Role::class);

        if (is_int($role)) {
            return $roleModel::query()->findOrFail($role);
        }

        $organisationEnabled = (bool) config('pbac.organisation.enabled', false);
        $organisationForeignKey = config('pbac.column_names.organisation_foreign_key', 'organisation_id');
        $organisationId = app(OrganisationResolver::class)->getOrganisationId();

        // Strict scope resolution to avoid name-collision footguns:
        //   - org feature disabled:   query by name only.
        //   - global: true:           force organisation_id IS NULL, ignoring the resolver.
        //   - global: false:          require an active organisation; otherwise throw.
        // Authorization itself unions global and org-scoped grants via RolePermissionQuery;
        // this lookup intentionally never falls back across scopes.
        $query = $roleModel::query()->where('name', $role);

        if ($organisationEnabled) {
            if ($global) {
                $query->whereNull($organisationForeignKey);
            } else {
                if ($organisationId === null) {
                    throw new InvalidArgumentException(
                        "Refusing to resolve role [{$role}]: no active organisation. "
                            .'Pass `global: true` to target a global role explicitly, or wrap the call in Pbac::withOrganisation(...).'
                    );
                }

                $query->where($organisationForeignKey, $organisationId);
            }
        }

        $found = $query->first();

        if ($found === null) {
            $scope = $organisationEnabled
                ? ' (organisation: '.($global ? 'global' : (string) $organisationId).')'
                : '';

            throw new InvalidArgumentException("Role [{$role}] not found".$scope.'.');
        }

        return $found;
    }

    private function resetPbacDecisionCache(): void
    {
        app(DecisionCache::class)->reset();
    }
}
