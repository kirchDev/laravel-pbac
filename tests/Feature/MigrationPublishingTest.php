<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use KirchDev\Pbac\PbacServiceProvider;

/**
 * The directory the package keeps its own migrations in.
 */
function packageMigrationPath(): string
{
    $path = __DIR__.'/../../database/migrations';

    return realpath($path) ?: $path;
}

/**
 * The package's source migrations, in the order their filenames prescribe.
 *
 * @return list<string>
 */
function packageMigrationSources(): array
{
    $sources = glob(packageMigrationPath().'/*.php') ?: [];
    sort($sources);

    return array_values($sources);
}

/**
 * The tables the package's own migrations create.
 *
 * @return list<string>
 */
function packageTables(): array
{
    /** @var array<string, string> $tables */
    $tables = config('pbac.table_names');

    return array_values($tables);
}

/**
 * What the provider offers for publishing: source path => published path.
 *
 * @return array<string, string>
 */
function publishedMigrations(): array
{
    return ServiceProvider::pathsToPublish(PbacServiceProvider::class, 'pbac-migrations');
}

/**
 * laravel-package-tools builds its target as `migrations/` . dirname($name) . `/`, and
 * dirname() of a bare filename is '.' — so every published path carries a literal '/./'.
 * The copy resolves it away; an assertion should not have to.
 */
function normalisePath(string $path): string
{
    return str_replace('/./', '/', $path);
}

/**
 * The publish map keyed by the source migration's filename, since the provider keys it by
 * its own unnormalised source paths.
 *
 * @return array<string, string>
 */
function publishedBySource(): array
{
    $map = [];

    foreach (publishedMigrations() as $source => $target) {
        $map[basename($source)] = normalisePath($target);
    }

    return $map;
}

/**
 * Strip the timestamp a published migration carries, leaving the part that identifies
 * which table it creates.
 */
function publishedMigrationName(string $file): string
{
    return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
}

/**
 * Point the application at a throwaway database path holding the migrations a consumer has
 * already published, then boot a fresh provider against it. Returns the temporary path; the
 * publish map it produced is read back through publishedMigrations().
 */
function publishAgainst(Application $app, string ...$alreadyPublished): string
{
    $database = sys_get_temp_dir().'/package-migrations-'.bin2hex(random_bytes(6));
    mkdir($database.'/migrations', 0o777, true);

    foreach ($alreadyPublished as $file) {
        touch($database.'/migrations/'.$file);
    }

    $app->useDatabasePath($database);
    (new PbacServiceProvider($app))->register()->boot();

    return $database;
}

function removeTemporaryDirectory(string $directory): void
{
    foreach (glob($directory.'/*') ?: [] as $entry) {
        is_dir($entry) ? removeTemporaryDirectory($entry) : unlink($entry);
    }

    rmdir($directory);
}

it('registers no migration path with the migrator', function () {
    $registered = array_map(
        static fn (string $path): string => realpath($path) ?: $path,
        app('migrator')->paths(),
    );

    // Neither the directory nor any single source file: runsMigrations() registers the files
    // one by one, which a directory-only check would sail straight past.
    expect($registered)->not->toContain(packageMigrationPath());

    foreach (packageMigrationSources() as $source) {
        expect($registered)->not->toContain($source);
    }
});

it('creates no package tables for an application that has not published the migrations', function () {
    $this->artisan('migrate:fresh')->run();

    foreach (packageTables() as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    // The suite runs the package migrations itself; migrate:fresh above dropped them.
    $this->artisan('migrate', [
        '--path' => packageMigrationPath(),
        '--realpath' => true,
    ])->run();
});

it('migrates the package tables from the package migration path in the test suite', function () {
    foreach (packageTables() as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('offers every package migration under the publish tag', function () {
    $published = publishedMigrations();

    expect($published)->toHaveCount(count(packageMigrationSources()));

    $sources = array_map(static fn (string $path): string => basename($path), array_keys($published));
    sort($sources);

    expect($sources)->toBe([
        '0001_01_01_000001_create_roles_table.php',
        '0001_01_01_000002_create_permissions_table.php',
        '0001_01_01_000003_create_role_has_permissions_table.php',
        '0001_01_01_000004_create_model_has_roles_table.php',
    ]);

    foreach ($published as $source => $target) {
        expect(is_file($source))->toBeTrue()
            ->and(dirname(normalisePath($target)))->toBe(database_path('migrations'))
            ->and(basename($target))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_\w+_table\.php$/');
    }
});

it('publishes the migrations in dependency order', function () {
    // The pivot tables carry foreign keys to roles and permissions. Publishing them all under
    // one timestamp would leave the migrator sorting them alphabetically, which puts
    // create_model_has_roles_table first and breaks the foreign key. The stamps must differ.
    $targets = array_map(static fn (string $path): string => basename($path), array_values(publishedMigrations()));

    sort($targets);

    expect(array_map('publishedMigrationName', $targets))->toBe([
        'create_roles_table.php',
        'create_permissions_table.php',
        'create_role_has_permissions_table.php',
        'create_model_has_roles_table.php',
    ]);
});

it('names every source migration with a timestamp prefix the publish strips', function () {
    // Two things ride on the prefix. The publish strips exactly this shape before stamping its
    // own, and until then it is what sorts the sources — the suite migrates them straight from
    // the package path, where an unprefixed name would run before create_roles_table exists.
    // The date is Laravel's own sentinel: it orders, it does not claim a day.
    foreach (packageMigrationSources() as $source) {
        expect(basename($source))->toMatch('/^0001_01_01_\d{6}_create_\w+_table\.php$/');
    }
});

it('stamps a newly added migration behind the ones already published', function () {
    // The common real change: a consumer published the earlier migrations long ago and the
    // package adds one. The published ones keep their filenames, and the new one has to land
    // behind them — that ordering is what the consumer's foreign keys depend on.
    $sources = packageMigrationSources();
    $added = array_pop($sources);

    $alreadyPublished = [];
    foreach ($sources as $offset => $source) {
        $alreadyPublished[basename($source)] = '2020_01_01_'.str_pad((string) $offset, 6, '0', STR_PAD_LEFT)
            .'_'.publishedMigrationName($source);
    }

    $database = publishAgainst($this->app, ...array_values($alreadyPublished));
    $map = publishedBySource();

    foreach ($alreadyPublished as $source => $name) {
        expect($map[$source])->toBe($database.'/migrations/'.$name);
    }

    $addedTarget = $map[basename($added)];

    expect(publishedMigrationName($addedTarget))->toBe(publishedMigrationName($added))
        ->and(basename($addedTarget))->not->toBe(basename($added));

    $targets = array_map(static fn (string $path): string => basename($path), array_values($map));
    $sorted = $targets;
    sort($sorted);

    expect($sorted)->toBe($targets)
        ->and(end($sorted))->toBe(basename($addedTarget));

    removeTemporaryDirectory($database);
});

it('hands the consumer a second migration when a source migration is renamed', function () {
    // The lookup matches on the published filename, so a source renamed since the consumer
    // published stops matching what they hold: they receive a second migration creating the
    // same table. Renaming a released migration is a breaking change; this pins why.
    $stale = '2020_01_01_000000_create_pbac_roles_table.php';
    $database = publishAgainst($this->app, $stale);

    $roles = publishedBySource()['0001_01_01_000001_create_roles_table.php'];

    expect(basename($roles))->not->toBe($stale);

    touch($roles);

    expect(glob($database.'/migrations/*_create_*roles_table.php'))->toHaveCount(2);

    removeTemporaryDirectory($database);
});

it('reuses an already published migration instead of stamping a second copy', function () {
    $existing = '2020_01_01_000000_create_roles_table.php';
    $database = publishAgainst($this->app, $existing);

    expect(publishedBySource()['0001_01_01_000001_create_roles_table.php'])
        ->toBe($database.'/migrations/'.$existing);

    removeTemporaryDirectory($database);
});
