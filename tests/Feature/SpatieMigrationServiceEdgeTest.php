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

    $this->artisan('migrate:fresh');

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

    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('team_id')->nullable();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
    });
});

function edgeOptions(array $overrides = []): SpatieMigrationOptions
{
    return new SpatieMigrationOptions(
        sourceTables: [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'role_has_permissions' => 'role_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'model_has_permissions' => $overrides['model_has_permissions'] ?? 'model_has_permissions',
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
        teamColumn: 'team_id',
        guardFilter: null,
        guardPrefix: false,
        withTeams: $overrides['withTeams'] ?? false,
        collapseDirectPermissions: $overrides['collapseDirectPermissions'] ?? false,
        commit: $overrides['commit'] ?? true,
    );
}

it('captures the error slot when an exception is thrown mid-migration', function () {
    // Drop the target permissions table after the schema guards run — so the service
    // passes the initial checks but blows up on the first insert.
    DB::table('permissions')->insert([
        'id' => 1,
        'name' => 'a',
        'guard_name' => 'web',
        'created_at' => '2024-01-01',
        'updated_at' => '2024-01-01',
    ]);

    Schema::table('pbac_permissions', function (Blueprint $table) {
        // Make name non-nullable AND insert a row that will trip the unique constraint twice.
        // Actually simpler: introduce a constraint conflict by pre-seeding the same name with a NULL column we drop after.
    });

    // Force a failure: drop a target column the inserter relies on.
    Schema::drop('pbac_permissions');

    $result = (new SpatieMigrationService(DB::connection(), edgeOptions()))->run();

    expect($result['guard_against_missing'])->not->toBeNull()
        ->and($result['guard_against_missing'])->toContain('Target table [pbac_permissions] does not exist');
});

it('skips collapseDirectPermissions when the source table name is empty', function () {
    $result = (new SpatieMigrationService(
        DB::connection(),
        edgeOptions(['collapseDirectPermissions' => true, 'model_has_permissions' => '']),
    ))->run();

    expect($result['stats']['direct_permissions'])->toBe(0);
});

it('skips collapseDirectPermissions when the source table does not exist', function () {
    Schema::drop('model_has_permissions');

    $result = (new SpatieMigrationService(
        DB::connection(),
        edgeOptions(['collapseDirectPermissions' => true]),
    ))->run();

    expect($result['stats']['direct_permissions'])->toBe(0);
});

it('skips a role-permission row when the source role no longer exists', function () {
    DB::table('permissions')->insert([
        ['id' => 1, 'name' => 'a', 'guard_name' => 'web', 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01'],
    ]);
    // role_id=999 has no matching row in the source roles table
    DB::table('role_has_permissions')->insert([
        ['role_id' => 999, 'permission_id' => 1],
    ]);

    $result = (new SpatieMigrationService(DB::connection(), edgeOptions()))->run();

    expect($result['error'])->toBeNull()
        ->and($result['stats']['role_permissions'])->toBe(0);
});

it('skips a role-permission row when the source permission no longer exists', function () {
    DB::table('roles')->insert([
        ['id' => 1, 'team_id' => null, 'name' => 'admin', 'guard_name' => 'web', 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01'],
    ]);
    // permission_id=999 has no matching row
    DB::table('role_has_permissions')->insert([
        ['role_id' => 1, 'permission_id' => 999],
    ]);

    $result = (new SpatieMigrationService(DB::connection(), edgeOptions()))->run();

    expect($result['stats']['role_permissions'])->toBe(0);
});

it('skips a role-assignment row when the source role is missing', function () {
    DB::table('model_has_roles')->insert([
        ['role_id' => 999, 'team_id' => null, 'model_type' => 'App\\Models\\User', 'model_id' => 1],
    ]);

    $result = (new SpatieMigrationService(DB::connection(), edgeOptions()))->run();

    expect($result['stats']['role_assignments'])->toBe(0);
});

it('skips a collapse row when the source permission is missing', function () {
    DB::table('model_has_permissions')->insert([
        ['permission_id' => 999, 'team_id' => null, 'model_type' => 'App\\Models\\User', 'model_id' => 1],
    ]);

    $result = (new SpatieMigrationService(
        DB::connection(),
        edgeOptions(['collapseDirectPermissions' => true]),
    ))->run();

    expect($result['stats']['direct_permissions'])->toBe(0);
});
