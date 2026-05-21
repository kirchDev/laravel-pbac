<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Console;

/**
 * Immutable bag of parameters that drives a single run of {@see SpatieMigrationService}.
 *
 * Source-side tables come from the running spatie/laravel-permission install;
 * target-side tables and column names come from the host application's
 * `config/pbac.php`.
 */
final readonly class SpatieMigrationOptions
{
    /**
     * @param  array{roles: string, permissions: string, role_has_permissions: string, model_has_roles: string, model_has_permissions: string}  $sourceTables
     * @param  array{roles: string, permissions: string, role_has_permissions: string, model_has_roles: string}  $targetTables
     * @param  array{role_pivot: string, permission_pivot: string, model_morph: string, target_morph: string, organisation: string}  $targetColumns
     */
    public function __construct(
        public array $sourceTables,
        public array $targetTables,
        public array $targetColumns,
        public string $teamColumn = 'team_id',
        public ?string $guardFilter = null,
        public bool $guardPrefix = false,
        public bool $withTeams = false,
        public bool $collapseDirectPermissions = false,
        public bool $commit = false,
    ) {}
}
