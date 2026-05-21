<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Queries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Support\ModelIdentifier;

final class RolePermissionQuery
{
    public function __construct(
        private readonly OrganisationResolver $organisationResolver,
    ) {}

    public function actorHasPermission(Model $actor, Model $permission, ?ModelIdentifier $target = null): bool
    {
        $permissionPivot = $this->permissionPivotKey();
        $query = $this->actorRolePermissionQuery($actor)
            ->where("role_permissions.{$permissionPivot}", $permission->getKey());

        $this->whereTargetMatches($query, $target);

        return $query->exists();
    }

    /**
     * @return list<string>
     */
    public function untargetedPermissionNamesFor(Model $actor): array
    {
        $tableNames = $this->tableNames();
        $permissionPivot = $this->permissionPivotKey();
        $targetMorphKey = $this->targetMorphKey();

        $permissions = $this->actorRolePermissionQuery($actor)
            ->join("{$tableNames['permissions']} as permissions", "role_permissions.{$permissionPivot}", '=', 'permissions.id')
            ->whereNull('role_permissions.target_type')
            ->whereNull("role_permissions.{$targetMorphKey}")
            ->whereNotNull('permissions.name')
            ->where('permissions.name', '<>', '')
            ->orderBy('permissions.name')
            ->distinct()
            ->pluck('permissions.name')
            ->filter(static fn (mixed $permissionName): bool => $permissionName !== null)
            ->values()
            ->map(static fn (mixed $permissionName): string => (string) $permissionName)
            ->all();

        return array_values($permissions);
    }

    private function actorRolePermissionQuery(Model $actor): QueryBuilder
    {
        $tableNames = $this->tableNames();
        $rolePivot = $this->rolePivotKey();
        $modelMorphKey = $this->modelMorphKey();

        $query = DB::table("{$tableNames['model_has_roles']} as model_roles")
            ->join("{$tableNames['roles']} as roles", "model_roles.{$rolePivot}", '=', 'roles.id')
            ->join("{$tableNames['role_has_permissions']} as role_permissions", 'roles.id', '=', "role_permissions.{$rolePivot}")
            ->where('model_roles.model_type', $actor->getMorphClass())
            ->where("model_roles.{$modelMorphKey}", $actor->getKey());

        $this->whereRoleMatchesOrganisation($query);

        return $query;
    }

    private function whereRoleMatchesOrganisation(QueryBuilder $query): void
    {
        if (! (bool) config('pbac.organisation.enabled', false)) {
            return;
        }

        $organisationForeignKey = $this->organisationForeignKey();
        $organisationId = $this->organisationResolver->getOrganisationId();

        $query->where(function (QueryBuilder $query) use ($organisationForeignKey, $organisationId): void {
            $query->whereNull("roles.{$organisationForeignKey}");

            if ($organisationId !== null) {
                $query->orWhere("roles.{$organisationForeignKey}", $organisationId);
            }
        });
    }

    private function whereTargetMatches(QueryBuilder $query, ?ModelIdentifier $target): void
    {
        $targetMorphKey = $this->targetMorphKey();

        $query->where(function (QueryBuilder $query) use ($target, $targetMorphKey): void {
            $query->where(function (QueryBuilder $query) use ($targetMorphKey): void {
                $query->whereNull('role_permissions.target_type')
                    ->whereNull("role_permissions.{$targetMorphKey}");
            });

            if ($target !== null) {
                $query->orWhere(function (QueryBuilder $query) use ($target, $targetMorphKey): void {
                    $query->where('role_permissions.target_type', $target->type)
                        ->where("role_permissions.{$targetMorphKey}", $target->id);
                });
            }
        });
    }

    /**
     * @return array{roles: string, permissions: string, role_has_permissions: string, model_has_roles: string}
     */
    private function tableNames(): array
    {
        $tableNames = config('pbac.table_names', []);

        return [
            'roles' => (string) ($tableNames['roles'] ?? 'roles'),
            'permissions' => (string) ($tableNames['permissions'] ?? 'permissions'),
            'role_has_permissions' => (string) ($tableNames['role_has_permissions'] ?? 'role_has_permissions'),
            'model_has_roles' => (string) ($tableNames['model_has_roles'] ?? 'model_has_roles'),
        ];
    }

    private function rolePivotKey(): string
    {
        return (string) (config('pbac.column_names.role_pivot_key') ?: 'role_id');
    }

    private function permissionPivotKey(): string
    {
        return (string) (config('pbac.column_names.permission_pivot_key') ?: 'permission_id');
    }

    private function modelMorphKey(): string
    {
        return (string) (config('pbac.column_names.model_morph_key') ?: 'model_id');
    }

    private function targetMorphKey(): string
    {
        return (string) (config('pbac.column_names.target_morph_key') ?: 'target_id');
    }

    private function organisationForeignKey(): string
    {
        return (string) config('pbac.column_names.organisation_foreign_key', 'organisation_id');
    }
}
