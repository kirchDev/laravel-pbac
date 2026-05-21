<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

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
        {--guard-prefix : Prefix permission and role names with "<guard>:" — useful when migrating multiple guards into the guard-less PBAC schema}
        {--with-teams : Carry team_id over as organisation_id on PBAC roles}
        {--collapse-direct-permissions : Materialise model_has_permissions as per-user roles named "user:<id>"}
        {--commit : Persist the migration. Without this flag the command runs as a dry-run inside a rolled-back transaction.}
    ';

    protected $description = 'Migrate spatie/laravel-permission data into the kirchdev/laravel-pbac tables.';

    public function handle(): int
    {
        $connection = $this->resolveConnection();
        $options = $this->buildOptions();

        $service = new SpatieMigrationService(
            $connection,
            $options,
            function (string $level, string $message): void {
                match ($level) {
                    'error' => $this->components->error($message),
                    'warn' => $this->components->warn($message),
                    default => $this->components->info($message),
                };
            },
        );

        $result = $service->run();

        if ($result['guard_against_overlap'] !== null) {
            $this->components->error($result['guard_against_overlap']);

            return self::FAILURE;
        }

        if ($result['guard_against_missing'] !== null) {
            $this->components->error($result['guard_against_missing']);

            return self::FAILURE;
        }

        if ($result['error'] !== null) {
            $this->components->error($result['error']);

            return self::FAILURE;
        }

        $this->table(
            ['Step', 'Rows processed'],
            [
                ['permissions', $result['stats']['permissions']],
                ['roles', $result['stats']['roles']],
                ['role_has_permissions', $result['stats']['role_permissions']],
                ['model_has_roles', $result['stats']['role_assignments']],
                ['direct_permissions → roles', $result['stats']['direct_permissions']],
            ],
        );

        if (! $result['committed']) {
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

    private function buildOptions(): SpatieMigrationOptions
    {
        $guard = $this->stringOption('guard');

        return new SpatieMigrationOptions(
            sourceTables: [
                'roles' => $this->stringOption('roles'),
                'permissions' => $this->stringOption('permissions'),
                'role_has_permissions' => $this->stringOption('role-permissions'),
                'model_has_roles' => $this->stringOption('model-roles'),
                'model_has_permissions' => $this->stringOption('model-permissions'),
            ],
            targetTables: [
                'roles' => $this->stringConfig('pbac.table_names.roles', 'roles'),
                'permissions' => $this->stringConfig('pbac.table_names.permissions', 'permissions'),
                'role_has_permissions' => $this->stringConfig('pbac.table_names.role_has_permissions', 'role_has_permissions'),
                'model_has_roles' => $this->stringConfig('pbac.table_names.model_has_roles', 'model_has_roles'),
            ],
            targetColumns: [
                'role_pivot' => $this->stringConfig('pbac.column_names.role_pivot_key', 'role_id', allowEmpty: false),
                'permission_pivot' => $this->stringConfig('pbac.column_names.permission_pivot_key', 'permission_id', allowEmpty: false),
                'model_morph' => $this->stringConfig('pbac.column_names.model_morph_key', 'model_id'),
                'target_morph' => $this->stringConfig('pbac.column_names.target_morph_key', 'target_id'),
                'organisation' => $this->stringConfig('pbac.column_names.organisation_foreign_key', 'organisation_id'),
            ],
            teamColumn: $this->stringOption('team-column'),
            guardFilter: $guard === '' ? null : $guard,
            guardPrefix: (bool) $this->option('guard-prefix'),
            withTeams: (bool) $this->option('with-teams'),
            collapseDirectPermissions: (bool) $this->option('collapse-direct-permissions'),
            commit: (bool) $this->option('commit'),
        );
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
}
