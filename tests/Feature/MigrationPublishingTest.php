<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use KirchDev\Pbac\PbacServiceProvider;

it('registers no migration path with the migrator', function () {
    $packagePath = realpath(__DIR__.'/../../database/migrations');

    $registered = array_map(
        static fn (string $path): string => realpath($path) ?: $path,
        app('migrator')->paths(),
    );

    expect($registered)->not->toContain($packagePath);
});

it('creates no pbac tables for an application that has not published the migrations', function () {
    $this->artisan('migrate:fresh')->run();

    foreach (config('pbac.table_names') as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('publishes the migrations under the pbac-migrations tag', function () {
    $paths = ServiceProvider::pathsToPublish(PbacServiceProvider::class, 'pbac-migrations');

    expect($paths)->toHaveCount(1);

    $source = (string) array_key_first($paths);

    expect(realpath($source))->toBe(realpath(__DIR__.'/../../database/migrations'))
        ->and($paths[$source])->toBe(database_path('migrations'));
});

it('publishes the migrations under their original filenames', function () {
    // Consumers already have these recorded in their `migrations` table. Re-stamping them —
    // what publishesMigrations() does — would make Laravel treat them as unrun and re-issue
    // CREATE TABLE against tables that already exist.
    $restamped = array_map(
        static fn (string $path): string => realpath($path) ?: $path,
        ServiceProvider::publishableMigrationPaths(),
    );

    expect($restamped)->not->toContain(realpath(__DIR__.'/../../database/migrations'));

    $files = array_map('basename', glob(__DIR__.'/../../database/migrations/*.php') ?: []);
    sort($files);

    expect($files)->toBe([
        '2026_05_09_000001_create_roles_table.php',
        '2026_05_09_000002_create_permissions_table.php',
        '2026_05_09_000003_create_role_has_permissions_table.php',
        '2026_05_09_000004_create_model_has_roles_table.php',
    ]);
});
