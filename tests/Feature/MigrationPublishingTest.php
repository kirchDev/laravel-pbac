<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use KirchDev\Pbac\PbacServiceProvider;

/**
 * Strip the timestamp a published migration carries, leaving the part that identifies
 * which table it creates.
 */
function pbacMigrationTable(string $file): string
{
    return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
}

function pbacPublishedMigrations(): array
{
    return ServiceProvider::pathsToPublish(PbacServiceProvider::class, 'pbac-migrations');
}

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

it('offers every package migration under the pbac-migrations tag', function () {
    $published = pbacPublishedMigrations();

    expect($published)->toHaveCount(4);

    $sources = array_map(static fn (string $path): string => basename($path), array_keys($published));
    sort($sources);

    // The sequence prefix is the package's running order and is stripped on publish, so it has
    // to stay in step with the dependency order asserted below.
    expect($sources)->toBe([
        '00001_create_roles_table.php',
        '00002_create_permissions_table.php',
        '00003_create_role_has_permissions_table.php',
        '00004_create_model_has_roles_table.php',
    ]);

    foreach ($published as $source => $target) {
        expect(is_file($source))->toBeTrue()
            ->and(dirname($target))->toBe(database_path('migrations'))
            ->and(basename($target))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_\w+_table\.php$/');
    }
});

it('publishes the migrations in dependency order', function () {
    // The pivot tables carry foreign keys to roles and permissions. Publishing them all under
    // one timestamp would leave the migrator sorting them alphabetically, which puts
    // create_model_has_roles_table first and breaks the foreign key. The stamps must differ.
    $targets = array_map(static fn (string $path): string => basename($path), array_values(pbacPublishedMigrations()));

    sort($targets);

    expect(array_map('pbacMigrationTable', $targets))->toBe([
        'create_roles_table.php',
        'create_permissions_table.php',
        'create_role_has_permissions_table.php',
        'create_model_has_roles_table.php',
    ]);
});

it('reuses an already published migration instead of stamping a second copy', function () {
    $database = sys_get_temp_dir().'/pbac-publish-'.bin2hex(random_bytes(6));
    mkdir($database.'/migrations', 0o777, true);

    $existing = $database.'/migrations/2020_01_01_000000_create_roles_table.php';
    touch($existing);

    $this->app->useDatabasePath($database);
    (new PbacServiceProvider($this->app))->boot();

    $published = pbacPublishedMigrations();

    $roles = null;
    foreach ($published as $source => $target) {
        if (str_ends_with($source, '_create_roles_table.php')) {
            $roles = $target;
        }
    }

    expect($roles)->toBe($existing);

    unlink($existing);
    rmdir($database.'/migrations');
    rmdir($database);
});
