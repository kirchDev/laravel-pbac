<?php

declare(strict_types=1);

namespace KirchDev\Pbac;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use KirchDev\Pbac\Authorization\DecisionCache;
use KirchDev\Pbac\Authorization\PbacAuthorizer;
use KirchDev\Pbac\Authorization\PermissionResolver;
use KirchDev\Pbac\Console\MigrateFromSpatieCommand;
use KirchDev\Pbac\Contracts\Authorizer;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Gate\PbacGate;
use KirchDev\Pbac\Queries\RolePermissionQuery;

class PbacServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pbac.php', 'pbac');

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

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/pbac.php' => config_path('pbac.php'),
        ], 'pbac-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'pbac-migrations');

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
