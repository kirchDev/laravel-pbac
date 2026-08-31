<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use KirchDev\Pbac\PbacServiceProvider;
use KirchDev\Pbac\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            PbacServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConfig());

        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('pbac.organisation.enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function databaseConfig(): array
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        return match ($driver) {
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 5432),
                'database' => getenv('DB_DATABASE') ?: 'pbac_test',
                'username' => getenv('DB_USERNAME') ?: 'pbac',
                'password' => getenv('DB_PASSWORD') ?: 'pbac',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->restoreBaselineSchema();
    }

    /**
     * The schema every test starts from: a clean database, the package's own migrations and
     * the fixture tables.
     *
     * A test that tears this down — migrate:fresh drops the host tables too, and the package
     * migrations carry foreign keys into them — calls this again rather than re-running the
     * package path alone, which would leave those foreign keys pointing at nothing.
     */
    protected function restoreBaselineSchema(): void
    {
        // migrate:fresh rather than migrate so persistent drivers (e.g. PostgreSQL in CI)
        // get a clean schema per test; in-memory SQLite is already fresh per process.
        $this->artisan('migrate:fresh')->run();
        $this->migratePackageMigrations();
        $this->createFixtureTables();
    }

    /**
     * Run the package's own migrations.
     *
     * The service provider deliberately does not call loadMigrationsFrom(): migrations are
     * publish-only for consumers, so the suite has to run them from the package path itself.
     * Using --path (rather than registering the path with the migrator) keeps the suite honest
     * about what a consuming application sees from the provider alone.
     */
    protected function migratePackageMigrations(): void
    {
        $this->artisan('migrate', [
            '--path' => realpath(__DIR__.'/../database/migrations'),
            '--realpath' => true,
        ])->run();
    }

    private function createFixtureTables(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('projects');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }
}
