<?php

declare(strict_types=1);

namespace KirchDev\Pbac;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use KirchDev\Pbac\Authorization\DecisionCache;
use KirchDev\Pbac\Authorization\PbacAuthorizer;
use KirchDev\Pbac\Authorization\PermissionResolver;
use KirchDev\Pbac\Console\AssignRoleCommand;
use KirchDev\Pbac\Console\MigrateFromSpatieCommand;
use KirchDev\Pbac\Console\RevokeRoleCommand;
use KirchDev\Pbac\Contracts\Authorizer;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Gate\PbacGate;
use KirchDev\Pbac\Queries\RolePermissionQuery;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PbacServiceProvider extends PackageServiceProvider
{
    /**
     * The package's shape: the config file, the always-on commands and the migrations.
     *
     * Migrations are discovered from database/migrations rather than listed here, so the
     * running order lives in the filenames and adding one is a single file. They are named
     * with Laravel's own sentinel date (0001_01_01_000001_create_roles_table.php): the
     * publish strips that prefix and stamps its own, and in the meantime it keeps the source
     * files sorting in dependency order for the suite, which migrates them from the package
     * path. runsMigrations() stays off — consumers publish, the provider never auto-loads.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-pbac')
            ->hasConfigFile()
            ->hasCommands(AssignRoleCommand::class, RevokeRoleCommand::class)
            ->discoversMigrations();
    }

    /**
     * Skip the migration processing entirely outside the console.
     *
     * Upstream computes each published name — which globs the consumer's database/migrations —
     * before its own runningInConsole() check, so a plain HTTP request would pay a directory
     * scan on every boot. Nothing but vendor:publish needs that map while runsMigrations() is
     * off; if it is ever switched on, loadMigrationsFrom() has to run and the guard stands down.
     */
    protected function bootPackageMigrations(): PackageServiceProvider
    {
        if (! $this->app->runningInConsole() && ! $this->package->runsMigrations) {
            return $this;
        }

        return parent::bootPackageMigrations();
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(OrganisationResolver::class, function ($app): OrganisationResolver {
            $resolver = $app['config']->get('pbac.organisation.resolver');

            return $app->make($resolver);
        });

        $this->app->scoped(DecisionCache::class);
        $this->app->scoped(PermissionResolver::class);
        $this->app->scoped(RolePermissionQuery::class);
        $this->app->scoped(Authorizer::class, PbacAuthorizer::class);
        $this->app->scoped(PbacGate::class);
        $this->app->scoped(PbacManager::class);
        $this->app->alias(PbacManager::class, 'pbac');
    }

    public function packageBooted(): void
    {
        if ((bool) config('pbac.gate.enabled', true) && (bool) config('pbac.gate.before_hook_enabled', true)) {
            Gate::before(fn ($user, string $ability, array $arguments): mixed => $this->app->make(PbacGate::class)->before($user, $ability, $arguments));
        }

        if ((bool) config('pbac.register_octane_reset_listener', false)) {
            $this->registerOctaneResetListeners();
        }

        if ($this->app->runningInConsole()) {
            $this->registerOptionalCommands();
        }

        $this->registerPermissionCacheInvalidation();
    }

    /**
     * Flush the per-request permission cache whenever a Permission row is created
     * or deleted within the same process — protects against stale negative hits
     * (e.g. a Gate::allows() returning false because we cached "doesn't exist"
     * before someone called Permission::findOrCreate() in the same request).
     */
    private function registerPermissionCacheInvalidation(): void
    {
        /** @var class-string<Model> $permissionModel */
        $permissionModel = (string) config('pbac.models.permission');

        $flush = function () {
            if ($this->app->resolved(PermissionResolver::class)) {
                $this->app->make(PermissionResolver::class)->flush();
            }
        };

        $permissionModel::created($flush);
        $permissionModel::deleted($flush);
    }

    /**
     * The one command configurePackage() cannot carry: hasCommands() registers
     * unconditionally, and this one is gated on config.
     */
    private function registerOptionalCommands(): void
    {
        $commands = [];

        if ((bool) config('pbac.commands.migrate_from_spatie', false)) {
            $commands[] = MigrateFromSpatieCommand::class;
        }

        if ($commands !== []) {
            $this->commands($commands);
        }
    }

    private function registerOctaneResetListeners(): void
    {
        foreach ([
            'Laravel\\Octane\\Events\\RequestTerminated',
            'Laravel\\Octane\\Events\\TaskTerminated',
            'Laravel\\Octane\\Events\\TickTerminated',
        ] as $event) {
            if (! class_exists($event)) {
                continue;
            }

            Event::listen($event, fn (object $event): null => $this->resetScopedState());
        }
    }

    private function resetScopedState(): null
    {
        if ($this->app->resolved('pbac')) {
            $this->app->make('pbac')->reset();
        }

        return null;
    }
}
