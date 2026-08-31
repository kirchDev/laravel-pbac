<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use KirchDev\Pbac\PbacServiceProvider;
use KirchDev\Pbac\Support\PackageMigrations;

/**
 * The directory the package keeps its own migrations in.
 */
function packageMigrationPath(): string
{
    $path = __DIR__.'/../../database/migrations';

    return realpath($path) ?: $path;
}

/**
 * The package's source migrations, in the order their sequence prefix prescribes.
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
 * Strip the timestamp a published migration carries, leaving the part that identifies
 * which table it creates.
 */
function publishedMigrationName(string $file): string
{
    return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
}

/**
 * A throwaway directory, so a source migration can be renamed or added without touching
 * the package.
 */
function makeTemporaryDirectory(string ...$files): string
{
    $directory = sys_get_temp_dir().'/package-migrations-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o777, true);

    foreach ($files as $file) {
        touch($directory.'/'.$file);
    }

    return $directory;
}

function removeTemporaryDirectory(string $directory): void
{
    foreach (glob($directory.'/*') ?: [] as $entry) {
        is_dir($entry) ? removeTemporaryDirectory($entry) : unlink($entry);
    }

    rmdir($directory);
}

/**
 * Point the application at a throwaway database path holding the migrations a consumer has
 * already published, so a publish run can be watched against a consumer's tree.
 */
function useTemporaryDatabasePath(Application $app, string ...$published): string
{
    $database = makeTemporaryDirectory();
    mkdir($database.'/migrations', 0o777, true);

    foreach ($published as $file) {
        touch($database.'/migrations/'.$file);
    }

    $app->useDatabasePath($database);

    return $database;
}

it('registers no migration path with the migrator', function () {
    $registered = array_map(
        static fn (string $path): string => realpath($path) ?: $path,
        app('migrator')->paths(),
    );

    expect($registered)->not->toContain(packageMigrationPath());
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
    $targets = array_map(static fn (string $path): string => basename($path), array_values(publishedMigrations()));

    sort($targets);

    expect(array_map('publishedMigrationName', $targets))->toBe([
        'create_roles_table.php',
        'create_permissions_table.php',
        'create_role_has_permissions_table.php',
        'create_model_has_roles_table.php',
    ]);
});

it('names every source migration with a zero-padded sequence prefix', function () {
    // The publish order comes out of sort(), which is a string sort. An unpadded prefix would
    // silently reorder the package's own migrations, so every source carries the same width.
    $prefixes = array_map(
        static fn (string $path): string => explode('_', basename($path))[0],
        packageMigrationSources(),
    );

    expect($prefixes)->each->toMatch('/^\d{5}$/');
});

it('keeps the publish order once the sequence prefix reaches double digits', function () {
    $padded = makeTemporaryDirectory('00003_create_third_table.php', '00010_create_tenth_table.php');
    $unpadded = makeTemporaryDirectory('3_create_third_table.php', '10_create_tenth_table.php');

    $order = static fn (string $directory): array => array_map(
        'publishedMigrationName',
        array_values(PackageMigrations::publishMap($directory, 1_600_000_000)),
    );

    expect($order($padded))->toBe([
        'create_third_table.php',
        'create_tenth_table.php',
    ]);

    // Without the padding the same two publish the other way round: '10_' sorts before '3_'.
    expect($order($unpadded))->toBe([
        'create_tenth_table.php',
        'create_third_table.php',
    ]);

    removeTemporaryDirectory($padded);
    removeTemporaryDirectory($unpadded);
});

it('stamps a newly added migration behind the ones already published', function () {
    // The common real change: a consumer published the earlier migrations long ago and the
    // package adds one. The published ones keep their filenames, and the new one has to land
    // behind them — that ordering is what the consumer's foreign keys depend on.
    $sources = packageMigrationSources();
    $added = array_pop($sources);

    $alreadyPublished = [];
    foreach ($sources as $offset => $source) {
        $alreadyPublished[$source] = '2020_01_01_'.str_pad((string) $offset, 6, '0', STR_PAD_LEFT)
            .'_'.PackageMigrations::name($source);
    }

    $database = useTemporaryDatabasePath($this->app, ...array_values($alreadyPublished));

    $map = PackageMigrations::publishMap(packageMigrationPath());

    foreach ($alreadyPublished as $source => $name) {
        expect($map[$source])->toBe($database.'/migrations/'.$name);
    }

    expect(publishedMigrationName($map[$added]))->toBe(PackageMigrations::name($added))
        ->and(basename($map[$added]))->not->toBe(PackageMigrations::name($added));

    $targets = array_map(static fn (string $path): string => basename($path), array_values($map));
    $sorted = $targets;
    sort($sorted);

    expect($sorted)->toBe($targets)
        ->and(end($sorted))->toBe(basename($map[$added]));

    removeTemporaryDirectory($database);
});

it('hands the consumer a second migration when a source migration is renamed', function () {
    // The lookup matches on the published filename, so renaming a source inside the package
    // stops finding what the consumer already has and they receive a second migration creating
    // the same table. Renaming a released migration is a breaking change; this pins why.
    $existing = '2020_01_01_000000_create_roles_table.php';
    $database = useTemporaryDatabasePath($this->app, $existing);

    $renamed = makeTemporaryDirectory('00001_create_pbac_roles_table.php');
    $target = array_values(PackageMigrations::publishMap($renamed, 1_600_000_000))[0];

    touch($target);

    expect(glob($database.'/migrations/*_roles_table.php'))->toHaveCount(2);

    removeTemporaryDirectory($renamed);
    removeTemporaryDirectory($database);
});

it('reuses an already published migration instead of stamping a second copy', function () {
    $first = packageMigrationSources()[0];
    $existing = '2020_01_01_000000_'.PackageMigrations::name($first);

    $database = useTemporaryDatabasePath($this->app, $existing);

    (new PbacServiceProvider($this->app))->boot();

    // The provider keys its publish map by its own unnormalised source paths, so match on the
    // filename rather than on the path this test resolved.
    $target = null;
    foreach (publishedMigrations() as $source => $path) {
        if (basename($source) === basename($first)) {
            $target = $path;
        }
    }

    expect($target)->toBe($database.'/migrations/'.$existing);

    removeTemporaryDirectory($database);
});
