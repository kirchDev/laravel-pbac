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
use KirchDev\Pbac\Console\AssignRoleCommand;
use KirchDev\Pbac\Console\MigrateFromSpatieCommand;
use KirchDev\Pbac\Console\RevokeRoleCommand;
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
        $this->publishes([
            __DIR__.'/../config/pbac.php' => config_path('pbac.php'),
        ], 'pbac-config');

        $this->offerMigrationPublishing();

        if ((bool) config('pbac.gate.enabled', true) && (bool) config('pbac.gate.before_hook_enabled', true)) {
            Gate::before(fn ($user, string $ability, array $arguments): mixed => $this->app->make(PbacGate::class)->before($user, $ability, $arguments));
        }

        if ((bool) config('pbac.register_octane_reset_listener', false)) {
            $this->registerOctaneResetListeners();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AssignRoleCommand::class,
                RevokeRoleCommand::class,
            ]);

            $this->registerOptionalCommands();
        }

        $this->registerPermissionCacheInvalidation();
    }

    /**
     * Map every package migration onto the filename it gets inside the consuming application.
     *
     * Source files are named `<sequence>_<migration>` — 00001_create_roles_table.php and so on.
     * The sequence is the package's own running order and never leaves the package: publishing
     * splits it off and stamps what remains with the publish time, one second per position.
     * That keeps both pivot tables behind the roles and permissions tables they reference,
     * while the migrations still land in the consumer's own timeline rather than ours.
     */
    private function offerMigrationPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $sources = glob(__DIR__.'/../database/migrations/*.php') ?: [];
        sort($sources);

        $publishedAt = time();
        $paths = [];

        foreach ($sources as $offset => $source) {
            $name = (string) preg_replace('/^\d+_/', '', basename($source));

            $paths[$source] = $this->publishedMigrationPath($name, $publishedAt + $offset);
        }

        $this->publishes($paths, 'pbac-migrations');
    }

    /**
     * Where a published migration lands.
     *
     * An already published copy keeps the filename it has, so re-running the publish never
     * leaves a consumer with two migrations creating the same table. Only a migration that
     * is not there yet gets a fresh stamp.
     */
    private function publishedMigrationPath(string $name, int $timestamp): string
    {
        $directory = database_path('migrations');
        $existing = glob($directory.DIRECTORY_SEPARATOR.'*_'.$name) ?: [];

        return $existing[0] ?? $directory.DIRECTORY_SEPARATOR.date('Y_m_d_His', $timestamp).'_'.$name;
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
