<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MigrateFromSpatieCommand extends Command
{
    protected $signature = 'pbac:migrate-from-spatie
        {--connection= : Database connection to operate on (defaults to the app default)}
        {--roles=roles : Source Spatie roles table}
        {--permissions=permissions : Source Spatie permissions table}
        {--role-permissions=role_has_permissions : Source role_has_permissions table}
        {--model-roles=model_has_roles : Source model_has_roles table}
        {--model-permissions=model_has_permissions : Source model_has_permissions table (empty string disables direct-permission handling)}
        {--team-column=team_id : Spatie team column on roles/model_has_roles/model_has_permissions}
        {--guard= : Only migrate rows where guard_name matches this value (otherwise all guards are imported)}
        {--guard-prefix : Prefix permission and role names with "<guard>:" - useful when migrating multiple guards into the guard-less PBAC schema}
        {--with-teams : Carry team_id over as organisation_id on PBAC roles}
        {--collapse-direct-permissions : Materialise model_has_permissions as per-user roles named "user:<id>"}
        {--commit : Persist the migration. Without this flag the command runs as a dry-run inside a rolled-back transaction.}
    ';

    protected $description = 'Migrate spatie/laravel-permission data into the kirchdev/laravel-pbac tables.';

    /** @var array<string, string> */
    private array $source = [];

    /** @var array<string, string> */
    private array $target = [];

    /** @var array<string, string> */
    private array $sourceColumns = [];

    /** @var array<string, string> */
    private array $targetColumns = [];

    public function handle(): int
    {
        $connection = $this->resolveConnection();

        $this->source = [
            'roles' => $this->stringOption('roles'),
            'permissions' => $this->stringOption('permissions'),
            'role_has_permissions' => $this->stringOption('role-permissions'),
            'model_has_roles' => $this->stringOption('model-roles'),
            'model_has_permissions' => $this->stringOption('model-permissions'),
        ];

        $this->target = [
            'roles' => $this->stringConfig('pbac.table_names.roles', 'roles'),
            'permissions' => $this->stringConfig('pbac.table_names.permissions', 'permissions'),
            'role_has_permissions' => $this->stringConfig('pbac.table_names.role_has_permissions', 'role_has_permissions'),
            'model_has_roles' => $this->stringConfig('pbac.table_names.model_has_roles', 'model_has_roles'),
        ];

        $this->targetColumns = [
            'role_pivot' => $this->stringConfig('pbac.column_names.role_pivot_key', 'role_id', allowEmpty: false),
            'permission_pivot' => $this->stringConfig('pbac.column_names.permission_pivot_key', 'permission_id', allowEmpty: false),
            'model_morph' => $this->stringConfig('pbac.column_names.model_morph_key', 'model_id'),
            'target_morph' => $this->stringConfig('pbac.column_names.target_morph_key', 'target_id'),
            'organisation' => $this->stringConfig('pbac.column_names.organisation_foreign_key', 'organisation_id'),
        ];

        $this->sourceColumns = [
            'team' => $this->stringOption('team-column'),
        ];

        if ($error = $this->guardAgainstTableOverlap()) {
            $this->components->error($error);

            return self::FAILURE;
        }

        if ($error = $this->guardAgainstMissingTables($connection)) {
            $this->components->error($error);

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');

        $this->components->info($commit
            ? 'Running migration in COMMIT mode.'
            : 'Running migration in DRY-RUN mode (use --commit to persist).');

        $stats = [
            'permissions' => 0,
            'roles' => 0,
            'role_permissions' => 0,
            'role_assignments' => 0,
            'direct_permissions' => 0,
        ];

        $runner = function (Closure $closure) use ($connection, $commit): void {
            if ($commit) {
                $connection->transaction(function () use ($closure): void {
                    $closure();
                });

                return;
            }

            $connection->beginTransaction();

            try {
                $closure();
            } finally {
                $connection->rollBack();
            }
        };

        try {
            $runner(function () use ($connection, &$stats): void {
                $stats['permissions'] = $this->migratePermissions($connection);
                $stats['roles'] = $this->migrateRoles($connection);
                $stats['role_permissions'] = $this->migrateRolePermissions($connection);
                $stats['role_assignments'] = $this->migrateRoleAssignments($connection);

                if ($this->shouldCollapseDirectPermissions($connection)) {
                    $stats['direct_permissions'] = $this->collapseDirectPermissions($connection);
                }
            });
        } catch (Throwable $exception) {
            $this->components->error('Migration aborted: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Step', 'Rows processed'],
            [
                ['permissions', $stats['permissions']],
                ['roles', $stats['roles']],
                ['role_has_permissions', $stats['role_permissions']],
                ['model_has_roles', $stats['role_assignments']],
                ['direct_permissions → roles', $stats['direct_permissions']],
            ],
        );

        if (! $commit) {
            $this->components->warn('Dry-run finished. Re-run with --commit to persist.');
        } else {
            $this->components->info('Migration complete.');
        }

        return self::SUCCESS;
    }

    private function resolveConnection(): Connection
    {
        $name = $this->option('connection');

        return is_string($name) && $name !== '' ? DB::connection($name) : DB::connection();
    }

    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }

    private function stringConfig(string $key, string $default, bool $allowEmpty = true): string
    {
        $value = config($key, $default);

        if (! is_string($value) || (! $allowEmpty && $value === '')) {
            return $default;
        }

        return $value;
    }

    private function guardAgainstTableOverlap(): ?string
    {
        foreach (['roles', 'permissions', 'role_has_permissions', 'model_has_roles'] as $key) {
            if ($this->source[$key] === $this->target[$key]) {
                return "Source and target tables overlap on [{$key}]. Rename PBAC tables via config/pbac.php table_names before running this command.";
            }
        }

        return null;
    }

    private function guardAgainstMissingTables(Connection $connection): ?string
    {
        $required = [$this->source['roles'], $this->source['permissions'], $this->source['role_has_permissions'], $this->source['model_has_roles']];

        foreach ($required as $table) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return "Source table [{$table}] does not exist on connection [{$connection->getName()}].";
            }
        }

        foreach ($this->target as $table) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return "Target table [{$table}] does not exist. Run [php artisan migrate] first.";
            }
        }

        return null;
    }

    private function migratePermissions(Connection $connection): int
    {
        $count = 0;
        $query = $connection->table($this->source['permissions']);

        $this->applyGuardFilter($query);

        $query->orderBy('id')->each(function (object $row) use ($connection, &$count): void {
            $connection->table($this->target['permissions'])->updateOrInsert(
                ['name' => $this->prefixAbility($row->name, $row->guard_name ?? null)],
                $this->withTimestamps([], $row),
            );

            $count++;
        });

        return $count;
    }

    private function migrateRoles(Connection $connection): int
    {
        $count = 0;
        $query = $connection->table($this->source['roles']);

        $this->applyGuardFilter($query);

        $withTeams = (bool) $this->option('with-teams');
        $teamColumn = $this->sourceColumns['team'];
        $orgColumn = $this->targetColumns['organisation'];
        $hasTeamColumn = $connection->getSchemaBuilder()->hasColumn($this->source['roles'], $teamColumn);

        $query->orderBy('id')->each(function (object $row) use ($connection, &$count, $withTeams, $teamColumn, $orgColumn, $hasTeamColumn): void {
            $organisationId = ($withTeams && $hasTeamColumn) ? ($row->{$teamColumn} ?? null) : null;

            $unique = ['name' => $this->prefixAbility($row->name, $row->guard_name ?? null)];

            if ($withTeams) {
                $unique[$orgColumn] = $organisationId;
            }

            $connection->table($this->target['roles'])->updateOrInsert(
                $unique,
                $this->withTimestamps([], $row),
            );

            $count++;
        });

        return $count;
    }

    private function migrateRolePermissions(Connection $connection): int
    {
        $count = 0;

        $connection->table($this->source['role_has_permissions'])
            ->orderBy($this->source['role_has_permissions'].'.role_id')
            ->each(function (object $row) use ($connection, &$count): void {
                $roleId = $this->resolveTargetRoleId($connection, (int) $row->role_id);
                $permissionId = $this->resolveTargetPermissionId($connection, (int) $row->permission_id);

                if ($roleId === null || $permissionId === null) {
                    return;
                }

                $connection->table($this->target['role_has_permissions'])->updateOrInsert([
                    $this->targetColumns['role_pivot'] => $roleId,
                    $this->targetColumns['permission_pivot'] => $permissionId,
                    'target_type' => null,
                    $this->targetColumns['target_morph'] => null,
                ]);

                $count++;
            });

        return $count;
    }

    private function migrateRoleAssignments(Connection $connection): int
    {
        $count = 0;

        $connection->table($this->source['model_has_roles'])
            ->orderBy('role_id')
            ->each(function (object $row) use ($connection, &$count): void {
                $roleId = $this->resolveTargetRoleId($connection, (int) $row->role_id);

                if ($roleId === null) {
                    return;
                }

                $connection->table($this->target['model_has_roles'])->updateOrInsert([
                    $this->targetColumns['role_pivot'] => $roleId,
                    'model_type' => $row->model_type,
                    $this->targetColumns['model_morph'] => $row->model_id,
                ]);

                $count++;
            });

        return $count;
    }

    private function shouldCollapseDirectPermissions(Connection $connection): bool
    {
        if (! (bool) $this->option('collapse-direct-permissions')) {
            return false;
        }

        if ($this->source['model_has_permissions'] === '') {
            return false;
        }

        return $connection->getSchemaBuilder()->hasTable($this->source['model_has_permissions']);
    }

    private function collapseDirectPermissions(Connection $connection): int
    {
        $count = 0;
        $withTeams = (bool) $this->option('with-teams');
        $teamColumn = $this->sourceColumns['team'];
        $orgColumn = $this->targetColumns['organisation'];
        $rolePivot = $this->targetColumns['role_pivot'];
        $permissionPivot = $this->targetColumns['permission_pivot'];
        $modelMorph = $this->targetColumns['model_morph'];
        $targetMorph = $this->targetColumns['target_morph'];

        $hasTeamColumn = $connection->getSchemaBuilder()->hasColumn($this->source['model_has_permissions'], $teamColumn);

        $connection->table($this->source['model_has_permissions'])
            ->orderBy('model_id')
            ->each(function (object $row) use ($connection, &$count, $withTeams, $teamColumn, $orgColumn, $rolePivot, $permissionPivot, $modelMorph, $targetMorph, $hasTeamColumn): void {
                $permissionId = $this->resolveTargetPermissionId($connection, (int) $row->permission_id);

                if ($permissionId === null) {
                    return;
                }

                $roleName = sprintf('user:%s', $row->model_id);
                $organisationId = ($withTeams && $hasTeamColumn) ? ($row->{$teamColumn} ?? null) : null;

                $unique = ['name' => $roleName];

                if ($withTeams) {
                    $unique[$orgColumn] = $organisationId;
                }

                $now = $connection->raw('CURRENT_TIMESTAMP');
                $connection->table($this->target['roles'])->updateOrInsert(
                    $unique,
                    ['created_at' => $now, 'updated_at' => $now],
                );

                $roleId = $connection->table($this->target['roles'])
                    ->where('name', $roleName)
                    ->when($withTeams, fn ($q) => $q->where($orgColumn, $organisationId))
                    ->value('id');

                if ($roleId === null) {
                    return;
                }

                $connection->table($this->target['role_has_permissions'])->updateOrInsert([
                    $rolePivot => $roleId,
                    $permissionPivot => $permissionId,
                    'target_type' => null,
                    $targetMorph => null,
                ]);

                $connection->table($this->target['model_has_roles'])->updateOrInsert([
                    $rolePivot => $roleId,
                    'model_type' => $row->model_type,
                    $modelMorph => $row->model_id,
                ]);

                $count++;
            });

        return $count;
    }

    private function resolveTargetRoleId(Connection $connection, int $sourceId): int|string|null
    {
        $row = $connection->table($this->source['roles'])->where('id', $sourceId)->first();

        if ($row === null) {
            return null;
        }

        $query = $connection->table($this->target['roles'])
            ->where('name', $this->prefixAbility($row->name, $row->guard_name ?? null));

        if ((bool) $this->option('with-teams') && property_exists($row, $this->sourceColumns['team'])) {
            $query->where($this->targetColumns['organisation'], $row->{$this->sourceColumns['team']});
        }

        return $query->value('id');
    }

    private function resolveTargetPermissionId(Connection $connection, int $sourceId): int|string|null
    {
        $row = $connection->table($this->source['permissions'])->where('id', $sourceId)->first();

        if ($row === null) {
            return null;
        }

        return $connection->table($this->target['permissions'])
            ->where('name', $this->prefixAbility($row->name, $row->guard_name ?? null))
            ->value('id');
    }

    private function applyGuardFilter(Builder $query): void
    {
        $guard = $this->stringOption('guard');

        if ($guard !== '') {
            $query->where('guard_name', $guard);
        }
    }

    private function prefixAbility(string $name, ?string $guard): string
    {
        if (! (bool) $this->option('guard-prefix') || $guard === null || $guard === '') {
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
}
