<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Console;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Pure migration logic for the spatie/laravel-permission → kirchdev/laravel-pbac transition.
 *
 * Isolated from {@see MigrateFromSpatieCommand} so the migration can be unit-tested without
 * an Artisan console roundtrip. The command is responsible for option parsing, output styling
 * and exit codes; this class only knows about DB rows.
 */
final class SpatieMigrationService
{
    /** @var Closure(string, string): void */
    private Closure $log;

    public function __construct(
        private readonly Connection $connection,
        private readonly SpatieMigrationOptions $options,
        ?Closure $log = null,
    ) {
        /** @var Closure(string, string): void $log */
        $log = $log ?? static fn (string $level, string $message): null => null;

        $this->log = $log;
    }

    /**
     * Run the migration. Returns row-count stats keyed by step.
     *
     * @return array{
     *     guard_against_overlap: ?string,
     *     guard_against_missing: ?string,
     *     stats: array{permissions: int, roles: int, role_permissions: int, role_assignments: int, direct_permissions: int},
     *     error: ?string,
     *     committed: bool
     * }
     */
    public function run(): array
    {
        $stats = [
            'permissions' => 0,
            'roles' => 0,
            'role_permissions' => 0,
            'role_assignments' => 0,
            'direct_permissions' => 0,
        ];

        if ($overlap = $this->guardAgainstTableOverlap()) {
            return ['guard_against_overlap' => $overlap, 'guard_against_missing' => null, 'stats' => $stats, 'error' => null, 'committed' => false];
        }

        if ($missing = $this->guardAgainstMissingTables()) {
            return ['guard_against_overlap' => null, 'guard_against_missing' => $missing, 'stats' => $stats, 'error' => null, 'committed' => false];
        }

        $this->emit('info', $this->options->commit
            ? 'Running migration in COMMIT mode.'
            : 'Running migration in DRY-RUN mode (use --commit to persist).');

        $runner = function (Closure $closure): void {
            if ($this->options->commit) {
                $this->connection->transaction(function () use ($closure): void {
                    $closure();
                });

                return;
            }

            $this->connection->beginTransaction();

            try {
                $closure();
            } finally {
                $this->connection->rollBack();
            }
        };

        try {
            $runner(function () use (&$stats): void {
                $stats['permissions'] = $this->migratePermissions();
                $stats['roles'] = $this->migrateRoles();
                $stats['role_permissions'] = $this->migrateRolePermissions();
                $stats['role_assignments'] = $this->migrateRoleAssignments();

                if ($this->shouldCollapseDirectPermissions()) {
                    $stats['direct_permissions'] = $this->collapseDirectPermissions();
                }
            });
        } catch (Throwable $exception) {
            return [
                'guard_against_overlap' => null,
                'guard_against_missing' => null,
                'stats' => $stats,
                'error' => 'Migration aborted: '.$exception->getMessage(),
                'committed' => false,
            ];
        }

        return [
            'guard_against_overlap' => null,
            'guard_against_missing' => null,
            'stats' => $stats,
            'error' => null,
            'committed' => $this->options->commit,
        ];
    }

    private function guardAgainstTableOverlap(): ?string
    {
        foreach (['roles', 'permissions', 'role_has_permissions', 'model_has_roles'] as $key) {
            if ($this->options->sourceTables[$key] === $this->options->targetTables[$key]) {
                return "Source and target tables overlap on [{$key}]. Rename PBAC tables via config/pbac.php table_names before running this command.";
            }
        }

        return null;
    }

    private function guardAgainstMissingTables(): ?string
    {
        $required = [
            $this->options->sourceTables['roles'],
            $this->options->sourceTables['permissions'],
            $this->options->sourceTables['role_has_permissions'],
            $this->options->sourceTables['model_has_roles'],
        ];

        foreach ($required as $table) {
            if (! $this->connection->getSchemaBuilder()->hasTable($table)) {
                return "Source table [{$table}] does not exist on connection [{$this->connection->getName()}].";
            }
        }

        foreach ($this->options->targetTables as $table) {
            if (! $this->connection->getSchemaBuilder()->hasTable($table)) {
                return "Target table [{$table}] does not exist. Run [php artisan migrate] first.";
            }
        }

        return null;
    }

    private function migratePermissions(): int
    {
        $count = 0;
        $query = $this->connection->table($this->options->sourceTables['permissions']);

        $this->applyGuardFilter($query);

        $query->orderBy('id')->each(function (object $row) use (&$count): void {
            $this->connection->table($this->options->targetTables['permissions'])->updateOrInsert(
                ['name' => $this->prefixAbility($row->name, $row->guard_name ?? null)],
                $this->withTimestamps([], $row),
            );

            $count++;
        });

        return $count;
    }

    private function migrateRoles(): int
    {
        $count = 0;
        $query = $this->connection->table($this->options->sourceTables['roles']);

        $this->applyGuardFilter($query);

        $teamColumn = $this->options->teamColumn;
        $orgColumn = $this->options->targetColumns['organisation'];
        $hasTeamColumn = $this->connection->getSchemaBuilder()->hasColumn($this->options->sourceTables['roles'], $teamColumn);

        $query->orderBy('id')->each(function (object $row) use (&$count, $teamColumn, $orgColumn, $hasTeamColumn): void {
            $organisationId = ($this->options->withTeams && $hasTeamColumn) ? ($row->{$teamColumn} ?? null) : null;

            $unique = ['name' => $this->prefixAbility($row->name, $row->guard_name ?? null)];

            if ($this->options->withTeams) {
                $unique[$orgColumn] = $organisationId;
            }

            $this->connection->table($this->options->targetTables['roles'])->updateOrInsert(
                $unique,
                $this->withTimestamps([], $row),
            );

            $count++;
        });

        return $count;
    }

    private function migrateRolePermissions(): int
    {
        $count = 0;

        $this->connection->table($this->options->sourceTables['role_has_permissions'])
            ->orderBy($this->options->sourceTables['role_has_permissions'].'.role_id')
            ->each(function (object $row) use (&$count): void {
                $roleId = $this->resolveTargetRoleId((int) $row->role_id);
                $permissionId = $this->resolveTargetPermissionId((int) $row->permission_id);

                if ($roleId === null || $permissionId === null) {
                    return;
                }

                $this->connection->table($this->options->targetTables['role_has_permissions'])->updateOrInsert([
                    $this->options->targetColumns['role_pivot'] => $roleId,
                    $this->options->targetColumns['permission_pivot'] => $permissionId,
                    'target_type' => null,
                    $this->options->targetColumns['target_morph'] => null,
                ]);

                $count++;
            });

        return $count;
    }

    private function migrateRoleAssignments(): int
    {
        $count = 0;

        $this->connection->table($this->options->sourceTables['model_has_roles'])
            ->orderBy('role_id')
            ->each(function (object $row) use (&$count): void {
                $roleId = $this->resolveTargetRoleId((int) $row->role_id);

                if ($roleId === null) {
                    return;
                }

                $this->connection->table($this->options->targetTables['model_has_roles'])->updateOrInsert([
                    $this->options->targetColumns['role_pivot'] => $roleId,
                    'model_type' => $row->model_type,
                    $this->options->targetColumns['model_morph'] => $row->model_id,
                ]);

                $count++;
            });

        return $count;
    }

    private function shouldCollapseDirectPermissions(): bool
    {
        if (! $this->options->collapseDirectPermissions) {
            return false;
        }

        if ($this->options->sourceTables['model_has_permissions'] === '') {
            return false;
        }

        return $this->connection->getSchemaBuilder()->hasTable($this->options->sourceTables['model_has_permissions']);
    }

    private function collapseDirectPermissions(): int
    {
        $count = 0;
        $teamColumn = $this->options->teamColumn;
        $orgColumn = $this->options->targetColumns['organisation'];
        $rolePivot = $this->options->targetColumns['role_pivot'];
        $permissionPivot = $this->options->targetColumns['permission_pivot'];
        $modelMorph = $this->options->targetColumns['model_morph'];
        $targetMorph = $this->options->targetColumns['target_morph'];

        $hasTeamColumn = $this->connection->getSchemaBuilder()->hasColumn($this->options->sourceTables['model_has_permissions'], $teamColumn);

        $this->connection->table($this->options->sourceTables['model_has_permissions'])
            ->orderBy('model_id')
            ->each(function (object $row) use (&$count, $teamColumn, $orgColumn, $rolePivot, $permissionPivot, $modelMorph, $targetMorph, $hasTeamColumn): void {
                $permissionId = $this->resolveTargetPermissionId((int) $row->permission_id);

                if ($permissionId === null) {
                    return;
                }

                $roleName = sprintf('user:%s', $row->model_id);
                $organisationId = ($this->options->withTeams && $hasTeamColumn) ? ($row->{$teamColumn} ?? null) : null;

                $unique = ['name' => $roleName];

                if ($this->options->withTeams) {
                    $unique[$orgColumn] = $organisationId;
                }

                $now = $this->connection->raw('CURRENT_TIMESTAMP');
                $this->connection->table($this->options->targetTables['roles'])->updateOrInsert(
                    $unique,
                    ['created_at' => $now, 'updated_at' => $now],
                );

                $roleId = $this->connection->table($this->options->targetTables['roles'])
                    ->where('name', $roleName)
                    ->when($this->options->withTeams, fn ($q) => $q->where($orgColumn, $organisationId))
                    ->value('id');

                if ($roleId === null) {
                    return;
                }

                $this->connection->table($this->options->targetTables['role_has_permissions'])->updateOrInsert([
                    $rolePivot => $roleId,
                    $permissionPivot => $permissionId,
                    'target_type' => null,
                    $targetMorph => null,
                ]);

                $this->connection->table($this->options->targetTables['model_has_roles'])->updateOrInsert([
                    $rolePivot => $roleId,
                    'model_type' => $row->model_type,
                    $modelMorph => $row->model_id,
                ]);

                $count++;
            });

        return $count;
    }

    private function resolveTargetRoleId(int $sourceId): int|string|null
    {
        $row = $this->connection->table($this->options->sourceTables['roles'])->where('id', $sourceId)->first();

        if ($row === null) {
            return null;
        }

        $query = $this->connection->table($this->options->targetTables['roles'])
            ->where('name', $this->prefixAbility($row->name, $row->guard_name ?? null));

        if ($this->options->withTeams && property_exists($row, $this->options->teamColumn)) {
            $query->where($this->options->targetColumns['organisation'], $row->{$this->options->teamColumn});
        }

        return $query->value('id');
    }

    private function resolveTargetPermissionId(int $sourceId): int|string|null
    {
        $row = $this->connection->table($this->options->sourceTables['permissions'])->where('id', $sourceId)->first();

        if ($row === null) {
            return null;
        }

        return $this->connection->table($this->options->targetTables['permissions'])
            ->where('name', $this->prefixAbility($row->name, $row->guard_name ?? null))
            ->value('id');
    }

    private function applyGuardFilter(Builder $query): void
    {
        if ($this->options->guardFilter !== null && $this->options->guardFilter !== '') {
            $query->where('guard_name', $this->options->guardFilter);
        }
    }

    private function prefixAbility(string $name, ?string $guard): string
    {
        if (! $this->options->guardPrefix || $guard === null || $guard === '') {
            return $name;
        }

        return $guard.':'.$name;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withTimestamps(array $attributes, object $row): array
    {
        if (property_exists($row, 'created_at') && $row->created_at !== null) {
            $attributes['created_at'] = $row->created_at;
        }

        if (property_exists($row, 'updated_at') && $row->updated_at !== null) {
            $attributes['updated_at'] = $row->updated_at;
        }

        return $attributes;
    }

    private function emit(string $level, string $message): void
    {
        ($this->log)($level, $message);
    }
}
