<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;
use KirchDev\Pbac\PbacManager;
use KirchDev\Pbac\Traits\HasRoles;

abstract class RoleCommand extends Command
{
    abstract protected function apply(Model $target, string $role, bool $global): void;

    public function handle(): int
    {
        $role = $this->stringArgument('role');
        $organisation = $this->option('organisation');
        $organisation = is_string($organisation) && $organisation !== '' ? $organisation : null;
        $global = (bool) $this->option('global');

        if (! $this->assertScopeIsExplicit($global, $organisation)) {
            return self::FAILURE;
        }

        $target = $this->resolveTarget($this->stringArgument('identifier'));

        if ($target === null) {
            return self::FAILURE;
        }

        try {
            if ($organisation !== null) {
                app(PbacManager::class)->withOrganisation(
                    $organisation,
                    fn (): null => $this->applyAndReturnNull($target, $role, false),
                );
            } else {
                $this->apply($target, $role, $global);
            }
        } catch (InvalidArgumentException $exception) {
            // HasRoles refuses to resolve a role across scopes; surface that as an
            // exit code rather than a stack trace.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderRoles($target);

        return self::SUCCESS;
    }

    /**
     * Print the target's role set as it stands after the change — the verification
     * a bootstrap invocation needs without a separate list command.
     */
    private function renderRoles(Model $target): void
    {
        $organisationEnabled = (bool) config('pbac.organisation.enabled', false);
        $organisationForeignKey = (string) config('pbac.column_names.organisation_foreign_key', 'organisation_id');

        $roles = $this->currentRoles($target);

        if ($roles === []) {
            $this->components->info('No roles assigned.');

            return;
        }

        $rows = [];

        foreach ($roles as $role) {
            $name = (string) $role->getAttribute('name');

            if (! $organisationEnabled) {
                $rows[] = [$name];

                continue;
            }

            $organisationId = $role->getAttribute($organisationForeignKey);

            $rows[] = [$name, $organisationId === null ? 'global' : (string) $organisationId];
        }

        $this->table($organisationEnabled ? ['Role', 'Scope'] : ['Role'], $rows);
    }

    /**
     * resolveTarget() has already rejected targets without the HasRoles trait; the
     * method_exists guard is what carries that guarantee across the call boundary.
     *
     * @return list<Model>
     */
    private function currentRoles(Model $target): array
    {
        if (! method_exists($target, 'roles')) {
            return [];
        }

        $relation = $target->roles();

        if (! $relation instanceof MorphToMany) {
            return [];
        }

        return array_values($relation->get()->all());
    }

    /**
     * Scope is never inferred: a forgotten flag must not silently grant a global role.
     */
    private function assertScopeIsExplicit(bool $global, ?string $organisation): bool
    {
        if (! (bool) config('pbac.organisation.enabled', false)) {
            if ($global || $organisation !== null) {
                $this->components->error('Organisation scoping is disabled (pbac.organisation.enabled), so --global and --organisation do not apply. Drop the flag.');

                return false;
            }

            return true;
        }

        if ($global && $organisation !== null) {
            $this->components->error('Pass either --global or --organisation=<id>, not both.');

            return false;
        }

        if (! $global && $organisation === null) {
            $this->components->error('A scope is required: pass --global for a global role, or --organisation=<id> for an organisation-bound one.');

            return false;
        }

        return true;
    }

    private function stringArgument(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
    }

    private function applyAndReturnNull(Model $target, string $role, bool $global): null
    {
        $this->apply($target, $role, $global);

        return null;
    }

    private function resolveTarget(string $identifier): ?Model
    {
        $class = $this->resolveTargetModelClass();

        if ($class === null) {
            return null;
        }

        $instance = new $class;

        $column = $this->option('column');
        $column = is_string($column) && $column !== '' ? $column : $instance->getKeyName();

        // Two matches are refused rather than silently resolved to the first row:
        // granting a role to the wrong record is not something a rerun undoes.
        $matches = $instance->newQuery()->where($column, $identifier)->limit(2)->get();

        if ($matches->count() > 1) {
            $this->components->error("Identifier [{$identifier}] is ambiguous on column [{$column}] of [{$class}]. Narrow it down.");

            return null;
        }

        $target = $matches->first();

        if ($target === null) {
            $this->components->error("No [{$class}] found with [{$column}] = [{$identifier}].");

            return null;
        }

        return $target;
    }

    /**
     * Resolution order: --model, then pbac.models.default_model, then the default
     * guard's auth provider model. The auth binding is deliberately the fallback,
     * not the primary path.
     *
     * @return class-string<Model>|null
     */
    private function resolveTargetModelClass(): ?string
    {
        $candidate = $this->targetModelCandidate();

        if ($candidate === null) {
            $this->components->error('Unable to determine the target model. Set pbac.models.default_model, configure an auth provider model, or pass --model=\\App\\Models\\User.');

            return null;
        }

        if (! class_exists($candidate) || ! is_subclass_of($candidate, Model::class)) {
            $this->components->error("Target model [{$candidate}] is not an Eloquent model class.");

            return null;
        }

        if (! in_array(HasRoles::class, class_uses_recursive($candidate), true)) {
            $this->components->error("Target model [{$candidate}] does not use the ".HasRoles::class.' trait, so roles cannot be assigned to it.');

            return null;
        }

        return $candidate;
    }

    private function targetModelCandidate(): ?string
    {
        $explicit = $this->option('model');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $configured = config('pbac.models.default_model');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $guard = config('auth.defaults.guard');
        $provider = config('auth.guards.'.$guard.'.provider');
        $model = config('auth.providers.'.$provider.'.model');

        return is_string($model) && $model !== '' ? $model : null;
    }
}
