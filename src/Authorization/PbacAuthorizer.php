<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Authorization;

use Illuminate\Database\Eloquent\Model;
use KirchDev\Pbac\Contracts\Authorizer;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Decision\Decision;
use KirchDev\Pbac\Decision\DecisionTrace;
use KirchDev\Pbac\Queries\RolePermissionQuery;
use KirchDev\Pbac\Support\ModelIdentifier;
use KirchDev\Pbac\Support\Target;

final class PbacAuthorizer implements Authorizer
{
    public function __construct(
        private readonly OrganisationResolver $organisationResolver,
        private readonly DecisionCache $cache,
        private readonly RolePermissionQuery $rolePermissionQuery,
    ) {}

    public function inspect(mixed $actor, string $ability, array $arguments = []): ?Decision
    {
        $target = Target::fromArguments($arguments);
        $cacheKey = $this->cacheKey($actor, $ability, $target);

        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        $decision = $this->inspectFresh($actor, $ability, $target);
        $this->cache->put($cacheKey, $decision);

        return $decision;
    }

    private function inspectFresh(mixed $actor, string $ability, ?ModelIdentifier $target): ?Decision
    {
        $trace = new DecisionTrace;

        if (! $actor instanceof Model) {
            return Decision::deny('pbac.actor_not_model', $trace->add('actor_not_model'));
        }

        $permission = $this->permission($ability);

        if ($permission === null) {
            $trace->add('permission_not_managed', ['ability' => $ability]);

            return (bool) config('pbac.gate.manage_existing_permissions_only', true)
                ? null
                : Decision::deny('pbac.permission_not_found', $trace);
        }

        $rolePermissionAllowed = $this->rolePermissionAllows($actor, $permission, $target, $trace);

        if ($rolePermissionAllowed) {
            return Decision::allow('pbac.role_permission_allowed', $trace->add('role_permission_allowed'));
        }

        return Decision::deny('pbac.no_matching_role_permission', $trace->add('no_matching_role_permission'));
    }

    private function permission(string $ability): ?Model
    {
        /** @var class-string<Model> $permissionModel */
        $permissionModel = config('pbac.models.permission');

        return $permissionModel::query()
            ->where('name', $ability)
            ->first();
    }

    private function rolePermissionAllows(Model $actor, Model $permission, ?ModelIdentifier $target, DecisionTrace $trace): bool
    {
        $allowed = $this->rolePermissionQuery->actorHasPermission($actor, $permission, $target);

        $trace->add('role_permission_query', [
            'allowed' => $allowed,
            'targeted' => $target !== null,
        ]);

        return $allowed;
    }

    private function cacheKey(mixed $actor, string $ability, ?ModelIdentifier $target): string
    {
        $actorKey = $actor instanceof Model
            ? $actor->getMorphClass().':'.$actor->getKey()
            : get_debug_type($actor);

        $targetKey = $target === null ? 'none' : $target->type.':'.$target->id;

        return implode('|', [
            $actorKey,
            $ability,
            (string) $this->organisationResolver->getOrganisationId(),
            $targetKey,
        ]);
    }
}
