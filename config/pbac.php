<?php

declare(strict_types=1);

use KirchDev\Pbac\Models\Permission;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Models\RoleAssignment;
use KirchDev\Pbac\Models\RolePermission;
use KirchDev\Pbac\Organisation\DefaultOrganisationResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Authorization Models
    |--------------------------------------------------------------------------
    |
    | These models are used by PBAC to retrieve roles, permissions, role
    | assignments, and role-permission grants. Applications may replace these
    | classes with their own implementations, for example to use UUID keys.
    |
    | "default_model" names the model the console commands (pbac:role:assign and
    | pbac:role:revoke) resolve their target against. When it is null they fall
    | back to the default guard's auth provider model; --model overrides both.
    |
    */

    'models' => [
        'role' => Role::class,
        'permission' => Permission::class,
        'role_assignment' => RoleAssignment::class,
        'role_permission' => RolePermission::class,
        'organisation' => null,
        'default_model' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | You may change these table names if your application already owns tables
    | with the default names or if you publish and customize the migrations.
    | The defaults intentionally follow the model and pivot names.
    |
    */

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'role_has_permissions' => 'role_has_permissions',
        'model_has_roles' => 'model_has_roles',
    ],

    /*
    |--------------------------------------------------------------------------
    | Column Names
    |--------------------------------------------------------------------------
    |
    | These options control the pivot and morph key column names. They follow
    | Laravel Permission style conventions, so applications with UUID columns
    | may use names such as "model_uuid" or "target_uuid".
    |
    */

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'target_morph_key' => 'target_id',
        'organisation_foreign_key' => 'organisation_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Types
    |--------------------------------------------------------------------------
    |
    | These values determine the column types used by the package migrations.
    | Configure them before running the migrations. Later changes require an
    | application-owned migration.
    |
    | Supported: "id", "uuid", "ulid"
    |
    */

    'keys' => [
        'primary_key_type' => 'id',
        'model_morph_key_type' => 'id',
        'target_morph_key_type' => 'id',
        'organisation_key_type' => 'id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Organisation Context
    |--------------------------------------------------------------------------
    |
    | When enabled, roles may be bound to a current organisation via the
    | configured organisation foreign key on the roles table. A null value
    | represents a global role.
    |
    */

    'organisation' => [
        'enabled' => false,
        'resolver' => DefaultOrganisationResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Reset Listener
    |--------------------------------------------------------------------------
    |
    | When enabled, PBAC may register a reset listener for long-running Octane
    | workers. By default this follows the application's Octane configuration,
    | while still allowing an explicit environment override.
    |
    */

    'register_octane_reset_listener' => env(
        'PBAC_REGISTER_OCTANE_RESET_LISTENER',
        (bool) env('OCTANE_SERVER', false)
    ),

    /*
    |--------------------------------------------------------------------------
    | Optional Console Commands
    |--------------------------------------------------------------------------
    |
    | PBAC ships a few opt-in maintenance commands that are not registered by
    | default. Flip the relevant flag (typically via an environment variable)
    | for a single deployment when you need the command, then turn it back
    | off. Commands that are not registered do not appear in [php artisan
    | list] and cannot be invoked.
    |
    */

    'commands' => [
        'migrate_from_spatie' => env('PBAC_ENABLE_SPATIE_MIGRATION_COMMAND', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate Integration
    |--------------------------------------------------------------------------
    |
    | PBAC integrates with Laravel's authorization layer so applications can use
    | $user->can(...), Gate::allows(...), and Gate::inspect(...). By default,
    | PBAC only handles abilities that exist as permissions.
    |
    */

    'gate' => [
        'enabled' => true,
        'before_hook_enabled' => true,
        'manage_existing_permissions_only' => true,
        'fallback_to_laravel_gates' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Decision Trace
    |--------------------------------------------------------------------------
    |
    | Tracing is intended for local debugging and tests. Production traces should
    | stay redacted so role names, permission internals, and target details are
    | not leaked through authorization responses.
    |
    | "redact" accepts:
    |   - null  → auto: redact when APP_ENV=production AND APP_DEBUG=false
    |   - true  → always redact (drop context arrays, keep step names)
    |   - false → never redact
    |
    | "log.enabled" enables structured logging of decisions via Laravel's logger.
    | "log.on" picks the trigger: "deny" (default) or "all".
    |
    */

    'trace' => [
        'enabled' => false,
        'redact' => null,
        'log' => [
            'enabled' => false,
            'channel' => null,
            'level' => 'info',
            'on' => 'deny',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Request-scoped decision caching keeps repeated authorization checks cheap
    | without leaking decisions across users or long-running workers. Persistent
    | cache keys are reserved for role and permission lookups.
    |
    */

    'cache' => [
        'decision_store' => 'request',
        'key' => 'pbac.cache',
        'store' => 'default',
    ],

];
