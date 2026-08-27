<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KirchDev\Pbac\Console\SpatieMigrationOptions;
use KirchDev\Pbac\Console\SpatieMigrationService;

beforeEach(function () {
    config()->set('pbac.table_names', [
        'roles' => 'pbac_roles',
        'permissions' => 'pbac_permissions',
        'role_has_permissions' => 'pbac_role_has_permissions',
        'model_has_roles' => 'pbac_model_has_roles',
    ]);

    Schema::dropIfExists('model_has_roles');
    Schema::dropIfExists('role_has_permissions');
    Schema::dropIfExists('permissions');
    Schema::dropIfExists('roles');

    $this->artisan('migrate:fresh')->run();
    $this->migratePackageMigrations();

    Schema::create('permissions', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['name', 'guard_name']);
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();
        $table->unique(['team_id', 'name', 'guard_name']);
    });

    Schema::create('role_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('role_id');
        $table->primary(['permission_id', 'role_id']);
    });

    Schema::create('model_has_roles', function (Blueprint $table) {
        $table->unsignedBigInteger('role_id');
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
});

function buildServiceOptions(array $overrides = []): SpatieMigrationOptions
{
    return new SpatieMigrationOptions(
        sourceTables: [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'role_has_permissions' => 'role_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'model_has_permissions' => '',
        ],
        targetTables: [
            'roles' => 'pbac_roles',
            'permissions' => 'pbac_permissions',
            'role_has_permissions' => 'pbac_role_has_permissions',
            'model_has_roles' => 'pbac_model_has_roles',
        ],
        targetColumns: [
            'role_pivot' => 'role_id',
            'permission_pivot' => 'permission_id',
            'model_morph' => 'model_id',
            'target_morph' => 'target_id',
            'organisation' => 'organisation_id',
        ],
        teamColumn: $overrides['teamColumn'] ?? 'team_id',
        guardFilter: $overrides['guardFilter'] ?? null,
        guardPrefix: $overrides['guardPrefix'] ?? false,
        withTeams: $overrides['withTeams'] ?? false,
        collapseDirectPermissions: $overrides['collapseDirectPermissions'] ?? false,
        commit: $overrides['commit'] ?? false,
    );
}

it('captures emitted log messages through the injected closure', function () {
    DB::table('permissions')->insert(['id' => 1, 'name' => 'posts.update', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00']);

    $entries = [];

    $service = new SpatieMigrationService(
        DB::connection(),
        buildServiceOptions(['commit' => true]),
        function (string $level, string $message) use (&$entries): void {
            $entries[] = [$level, $message];
        },
    );

    $result = $service->run();

    expect($result['error'])->toBeNull()
        ->and($result['committed'])->toBeTrue()
        ->and($entries)->toContain(['info', 'Running migration in COMMIT mode.']);
});

it('returns guard_against_overlap when source and target tables collide', function () {
    $options = new SpatieMigrationOptions(
        sourceTables: [
            'roles' => 'pbac_roles', // same as target — overlap
            'permissions' => 'permissions',
            'role_has_permissions' => 'role_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'model_has_permissions' => '',
        ],
        targetTables: [
            'roles' => 'pbac_roles',
            'permissions' => 'pbac_permissions',
            'role_has_permissions' => 'pbac_role_has_permissions',
            'model_has_roles' => 'pbac_model_has_roles',
        ],
        targetColumns: [
            'role_pivot' => 'role_id',
            'permission_pivot' => 'permission_id',
            'model_morph' => 'model_id',
            'target_morph' => 'target_id',
            'organisation' => 'organisation_id',
        ],
    );

    $result = (new SpatieMigrationService(DB::connection(), $options))->run();

    expect($result['guard_against_overlap'])->toContain('Source and target tables overlap on [roles]')
        ->and($result['stats']['permissions'])->toBe(0);
});

it('returns guard_against_missing when a required source table is absent', function () {
    Schema::drop('permissions');

    $result = (new SpatieMigrationService(DB::connection(), buildServiceOptions()))->run();

    expect($result['guard_against_missing'])->toContain('Source table [permissions] does not exist');
});

it('does not persist any rows when commit is false (dry-run rolls back inside a transaction)', function () {
    DB::table('permissions')->insert([
        ['id' => 1, 'name' => 'posts.update', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
    ]);

    DB::table('roles')->insert([
        ['id' => 1, 'team_id' => null, 'name' => 'admin', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
    ]);

    $result = (new SpatieMigrationService(DB::connection(), buildServiceOptions(['commit' => false])))->run();

    expect($result['error'])->toBeNull()
        ->and($result['committed'])->toBeFalse()
        ->and($result['stats']['permissions'])->toBe(1)
        ->and($result['stats']['roles'])->toBe(1)
        ->and(DB::table('pbac_permissions')->count())->toBe(0)
        ->and(DB::table('pbac_roles')->count())->toBe(0);
});

it('persists rows when commit is true and reports counts in the stats array', function () {
    DB::table('permissions')->insert([
        ['id' => 1, 'name' => 'posts.update', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
        ['id' => 2, 'name' => 'posts.delete', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
    ]);

    DB::table('roles')->insert([
        ['id' => 1, 'team_id' => null, 'name' => 'admin', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
    ]);

    DB::table('role_has_permissions')->insert([
        ['role_id' => 1, 'permission_id' => 1],
        ['role_id' => 1, 'permission_id' => 2],
    ]);

    DB::table('model_has_roles')->insert([
        ['role_id' => 1, 'team_id' => null, 'model_type' => 'App\\Models\\User', 'model_id' => 7],
    ]);

    $result = (new SpatieMigrationService(DB::connection(), buildServiceOptions(['commit' => true])))->run();

    expect($result['stats'])->toBe([
        'permissions' => 2,
        'roles' => 1,
        'role_permissions' => 2,
        'role_assignments' => 1,
        'direct_permissions' => 0,
    ])
        ->and(DB::table('pbac_permissions')->count())->toBe(2)
        ->and(DB::table('pbac_role_has_permissions')->count())->toBe(2);
});
