<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Move the PBAC tables aside so the source defaults (`roles`, `permissions`, …) are free for Spatie fixtures.
    config()->set('pbac.table_names', [
        'roles' => 'pbac_roles',
        'permissions' => 'pbac_permissions',
        'role_has_permissions' => 'pbac_role_has_permissions',
        'model_has_roles' => 'pbac_model_has_roles',
    ]);

    // Rerun the package migrations under the renamed targets.
    Schema::dropIfExists('model_has_roles');
    Schema::dropIfExists('role_has_permissions');
    Schema::dropIfExists('permissions');
    Schema::dropIfExists('roles');

    $this->artisan('migrate:fresh');

    createSpatieFixtureTables();
});

function createSpatieFixtureTables(): void
{
    Schema::create('permissions', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('guard_name');
        $table->timestamps();

        $table->unique(['name', 'guard_name']);
    });

    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable()->index();
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
        $table->unsignedBigInteger('team_id')->nullable()->index();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');

        $table->index(['model_id', 'model_type']);
        $table->primary(['team_id', 'role_id', 'model_id', 'model_type']);
    });

    Schema::create('model_has_permissions', function (Blueprint $table) {
        $table->unsignedBigInteger('permission_id');
        $table->unsignedBigInteger('team_id')->nullable()->index();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');

        $table->index(['model_id', 'model_type']);
        $table->primary(['team_id', 'permission_id', 'model_id', 'model_type']);
    });
}

function seedSpatieFixture(): void
{
    DB::table('permissions')->insert([
        ['id' => 1, 'name' => 'posts.update', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
        ['id' => 2, 'name' => 'posts.delete', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
        ['id' => 3, 'name' => 'billing.view', 'guard_name' => 'api', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
    ]);

    DB::table('roles')->insert([
        ['id' => 1, 'team_id' => null, 'name' => 'admin', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
        ['id' => 2, 'team_id' => 1, 'name' => 'editor', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
        ['id' => 3, 'team_id' => 2, 'name' => 'editor', 'guard_name' => 'web', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
    ]);

    DB::table('role_has_permissions')->insert([
        ['role_id' => 1, 'permission_id' => 1],
        ['role_id' => 1, 'permission_id' => 2],
        ['role_id' => 2, 'permission_id' => 1],
    ]);

    DB::table('model_has_roles')->insert([
        ['role_id' => 1, 'team_id' => null, 'model_type' => 'App\\Models\\User', 'model_id' => 10],
        ['role_id' => 2, 'team_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 20],
    ]);

    DB::table('model_has_permissions')->insert([
        ['permission_id' => 2, 'team_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 30],
    ]);
}

it('dry-runs the migration without persisting anything', function () {
    seedSpatieFixture();

    $this->artisan('pbac:migrate-from-spatie', ['--with-teams' => true])
        ->assertSuccessful();

    expect(DB::table('pbac_permissions')->count())->toBe(0)
        ->and(DB::table('pbac_roles')->count())->toBe(0)
        ->and(DB::table('pbac_role_has_permissions')->count())->toBe(0)
        ->and(DB::table('pbac_model_has_roles')->count())->toBe(0);
});

it('migrates permissions, roles, grants, and assignments when --commit is set', function () {
    seedSpatieFixture();

    $this->artisan('pbac:migrate-from-spatie', ['--with-teams' => true, '--commit' => true])
        ->assertSuccessful();

    expect(DB::table('pbac_permissions')->count())->toBe(3)
        ->and(DB::table('pbac_roles')->count())->toBe(3)
        ->and(DB::table('pbac_role_has_permissions')->count())->toBe(3)
        ->and(DB::table('pbac_model_has_roles')->count())->toBe(2);

    expect(DB::table('pbac_roles')->where('name', 'editor')->where('organisation_id', 1)->exists())->toBeTrue()
        ->and(DB::table('pbac_roles')->where('name', 'editor')->where('organisation_id', 2)->exists())->toBeTrue()
        ->and(DB::table('pbac_roles')->where('name', 'admin')->whereNull('organisation_id')->exists())->toBeTrue();
});

it('is idempotent - running --commit twice produces the same end state', function () {
    seedSpatieFixture();

    $this->artisan('pbac:migrate-from-spatie', ['--with-teams' => true, '--commit' => true])->assertSuccessful();
    $this->artisan('pbac:migrate-from-spatie', ['--with-teams' => true, '--commit' => true])->assertSuccessful();

    expect(DB::table('pbac_permissions')->count())->toBe(3)
        ->and(DB::table('pbac_roles')->count())->toBe(3)
        ->and(DB::table('pbac_role_has_permissions')->count())->toBe(3)
        ->and(DB::table('pbac_model_has_roles')->count())->toBe(2);
});

it('filters to a single guard when --guard is provided', function () {
    seedSpatieFixture();

    $this->artisan('pbac:migrate-from-spatie', ['--guard' => 'api', '--commit' => true])->assertSuccessful();

    expect(DB::table('pbac_permissions')->count())->toBe(1)
        ->and(DB::table('pbac_permissions')->where('name', 'billing.view')->exists())->toBeTrue();
});

it('namespaces ability names by guard when --guard-prefix is set', function () {
    seedSpatieFixture();

    $this->artisan('pbac:migrate-from-spatie', ['--guard-prefix' => true, '--commit' => true])->assertSuccessful();

    expect(DB::table('pbac_permissions')->where('name', 'web:posts.update')->exists())->toBeTrue()
        ->and(DB::table('pbac_permissions')->where('name', 'api:billing.view')->exists())->toBeTrue()
        ->and(DB::table('pbac_roles')->where('name', 'web:admin')->exists())->toBeTrue();
});

it('collapses direct user permissions into per-user roles when requested', function () {
    seedSpatieFixture();

    $this->artisan('pbac:migrate-from-spatie', [
        '--with-teams' => true,
        '--collapse-direct-permissions' => true,
        '--commit' => true,
    ])->assertSuccessful();

    $userRole = DB::table('pbac_roles')->where('name', 'user:30')->first();

    expect($userRole)->not->toBeNull()
        ->and($userRole->organisation_id)->toBe(1);

    expect(DB::table('pbac_role_has_permissions')->where('role_id', $userRole->id)->count())->toBe(1)
        ->and(DB::table('pbac_model_has_roles')->where('role_id', $userRole->id)->count())->toBe(1);
});

it('refuses to run when source and target tables overlap', function () {
    seedSpatieFixture();

    config()->set('pbac.table_names.roles', 'roles');

    $this->artisan('pbac:migrate-from-spatie', ['--commit' => true])
        ->expectsOutputToContain('Source and target tables overlap')
        ->assertFailed();
});

it('refuses to run when a required source table is missing', function () {
    seedSpatieFixture();

    Schema::drop('model_has_roles');

    $this->artisan('pbac:migrate-from-spatie', ['--commit' => true])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});
