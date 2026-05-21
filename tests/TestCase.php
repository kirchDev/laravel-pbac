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
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('pbac.organisation.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->run();
        $this->createFixtureTables();
    }

    private function createFixtureTables(): void
    {
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
