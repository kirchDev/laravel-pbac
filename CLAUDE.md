# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Agent instruction files

`CLAUDE.md` and `AGENTS.md` are kept **byte-identical**. `CLAUDE.md` is what Claude Code reads; `AGENTS.md` is what vendor-neutral agent tools read — Codex, OpenCode, Cursor, Copilot, and whatever follows them. Two real files, deliberately not a symlink: not every tool resolves one.

**After editing either file, copy it over the other — don't repeat the edit by hand:**

```bash
cp CLAUDE.md AGENTS.md   # or the reverse, whichever you just edited
```

Retyping a change is exactly how the two drift; one reflowed line or reworded clause is enough. `diff CLAUDE.md AGENTS.md` must print nothing. If it ever does, treat it as a defect and fix it by letting one file win wholesale — never by merging them.

## What this is

`kirchdev/laravel-pbac` is a standalone Composer **library** (not an application) that adds policy-based access control to Laravel 13: roles, permissions, organisation/tenant scoping, native `Gate` integration, and a per-request decision cache. PHP 8.4+, ships its own service provider auto-discovered via `extra.laravel.providers`.

The library has no host app — tests run against `orchestra/testbench` with in-memory SQLite.

## Commands

PHP (Composer scripts):

- `composer test` — Pest 4 suite via Testbench.
- `composer test -- --filter=SomeTest` — run a single test / pattern (Pest passes through PHPUnit args).
- `composer pint` — Laravel Pint in **test** mode (no writes). `composer pint:fix` to auto-fix.
- `composer larastan` — Larastan/PHPStan at `--memory-limit=512M`.

Node tooling (lint/format only, no app code):

- `pnpm check` / `pnpm check:fix` — oxlint + oxfmt over JS / JSON / YAML / MD.
- Husky runs Pint + Larastan + oxlint + oxfmt on commit via lint-staged. Don't `--no-verify` unless explicitly asked.

Commits **must** follow Conventional Commits (commitlint enforced). Releases are automated by release-please on `main`.

## Architecture

Everything is wired in `src/PbacServiceProvider.php` as `scoped` bindings (per-request lifetimes — important for Octane). The provider also:

- Registers a `Gate::before` hook (when `pbac.gate.enabled` and `pbac.gate.before_hook_enabled`) that delegates every ability check to `PbacGate`.
- Optionally subscribes to Octane `RequestTerminated` / `TaskTerminated` / `TickTerminated` events to call `PbacManager::reset()` and prevent state bleed between workers.

The request-time flow for `$user->can('ability', $target)`:

1. Laravel `Gate` fires the `before` hook → `PbacGate::before()`.
2. `PbacGate` asks `Authorizer` (bound to `PbacAuthorizer`) for a `Decision`.
3. `PbacAuthorizer` builds a cache key from **actor + ability + current organisation id + target morph/id**, checks `DecisionCache`, and on miss runs `inspectFresh`:
   - Looks up the `Permission` by name. If absent and `pbac.gate.manage_existing_permissions_only` is true → returns `null` so Laravel can fall back to native gates (controlled by `pbac.gate.fallback_to_laravel_gates`).
   - Delegates to `RolePermissionQuery::actorHasPermission()` which is the single source of truth for "does this actor have this permission, optionally on this target, in the current org scope".
4. The `Decision` carries a `DecisionTrace` (opt-in via `pbac.trace.enabled`, redacted in production by default) and is returned as a `Response::allow()` / `Response::deny($reason)`.

Organisation scoping lives entirely behind `OrganisationResolver` (interface in `src/Contracts/`, default in `src/Organisation/DefaultOrganisationResolver.php`, swappable via `pbac.organisation.resolver`). `PbacManager::withOrganisation()` / `withoutOrganisation()` save/restore the previous scope and **reset the `DecisionCache` on both enter and exit** — that reset is what guarantees checks don't bleed across tenants, so don't remove it when refactoring scope code.

`HasRoles` (in `src/Traits/`) is the trait host apps add to their User (or any authorizable model). It uses the configured pivot/morph column names from `pbac.column_names.*` and key types from `pbac.keys.*`, so the trait itself must stay agnostic to int/uuid/ulid keys.

Models in `src/Models/` (`Role`, `Permission`, `RoleAssignment`, `RolePermission`) are designed to be **swappable** — every consumer resolves the concrete class via `config('pbac.models.*')` rather than referencing the class directly. New code touching models should do the same.

## Things that are easy to get wrong

- `pbac.keys.*` (id / uuid / ulid) must be set **before** running the published migrations — the migration files read config at run time.
- All container bindings are `scoped`, not `singleton`. If you add a new stateful service, use `scoped` and make it implement `Contracts\Resettable` if it caches anything across a request.
- `PbacAuthorizer::inspect()` returns `?Decision` — a `null` return means "I don't manage this ability, let Laravel handle it." Don't conflate that with "deny."
- Migrations are **publish-only**: `configurePackage()` leaves `runsMigrations()` off, so the provider never calls `loadMigrationsFrom()` and consumers get schema changes only via `vendor:publish --tag=pbac-migrations`. `discoversMigrations()` maps each file individually and stamps the target with the publish time, one second per position — an already published copy keeps its filename, so re-publishing never duplicates a migration.
- Source migrations are named **`0001_01_01_<sequence>_<migration>`** — `0001_01_01_000001_create_roles_table.php`. The date is Laravel's own sentinel, not a claim about a day: `laravel-package-tools` strips exactly `/^\d{4}_\d{2}_\d{2}_\d{6}_/` before stamping its own, so the prefix never reaches the consumer, who sees `2026_08_27_143012_create_roles_table.php`. A new migration takes the next free sequence number.
- That **order is load-bearing**, not cosmetic, and the filename is the only thing carrying it — there is no list of migrations anywhere. Both pivot tables carry foreign keys to `roles`/`permissions`, so they must publish behind them, and the suite migrates the sources straight from the package path, where an unprefixed name would sort `create_model_has_roles_table` before `roles` exists. `MigrationPublishingTest` asserts the source names, the prefix shape and the resulting publish order.
- `laravel-package-tools` builds published paths as `migrations/` + `dirname($name)` + `/`, and `dirname()` of a bare filename is `.` — so every published path contains a literal `/./`. The copy resolves it; assertions must normalise it (`normalisePath()` in `MigrationPublishingTest`).
- `bootPackageMigrations()` is **overridden** to return early outside the console: upstream computes each published name — globbing the consumer's `database/migrations` — before its own `runningInConsole()` check, so a request-time boot would otherwise pay a directory scan per migration. The guard stands down if `runsMigrations()` is ever switched on.
- The provider extends `Spatie\LaravelPackageTools\PackageServiceProvider`. Wiring goes in `packageRegistered()` (bindings) and `packageBooted()` (Gate hook, Octane listeners, model events); `configurePackage()` carries only the config file, the always-on commands and the migrations. `hasCommands()` registers **unconditionally**, so the config-gated `MigrateFromSpatieCommand` stays in `registerOptionalCommands()`. Booting the provider by hand in a test needs `->register()->boot()` — `$this->package` is built in `register()`.
- Tests use Testbench; there is no `bootstrap/app.php`. Add new test setup to `tests/TestCase.php` / `tests/Pest.php`. Because the provider loads no migrations, the suite runs them itself in `TestCase::migratePackageMigrations()` — and a test that calls `migrate:fresh` must call `TestCase::restoreBaselineSchema()` afterwards, not the package path alone: `migrate:fresh` drops the host fixture tables too, and on a driver that enforces foreign keys at migration time (PostgreSQL in CI, unlike SQLite) re-running the package migrations without them fails.
- `resources/boost/` is **consumer-facing**, unlike `CLAUDE.md` / `AGENTS.md`. Laravel Boost discovers it purely from the filesystem — it reads the consumer's root `composer.json` and looks for `vendor/kirchdev/laravel-pbac/resources/boost/{guidelines,skills}` — so there is no dependency on `laravel/boost` here and nothing to register. The paths are the whole contract; renaming a directory removes the package from `boost:install` without an error.
- Third-party guidelines get **no version resolution and no fallback**: everything under `guidelines/` is always loaded, and a guideline that throws while rendering is silently replaced by an empty string. Keep `$assist` calls inside the documented `GuidelineAssist` surface (`BoostResourcesTest` asserts it against an allowlist and renders every guideline through Boost's own placeholder pipeline). A `SKILL.md` missing `name` or `description` frontmatter is discarded just as silently; keep that frontmatter flat `key: value`, since Boost parses it with `symfony/yaml` and the suite — which does not depend on it — parses it by hand.
