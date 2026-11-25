# kirchdev/laravel-pbac

A Composer **library**, not an app: policy-based access control for Laravel 13 on PHP 8.4+ — roles, permissions, organisation scoping, `Gate` integration, a per-request decision cache. There is no host app; tests run on `orchestra/testbench` (SQLite locally, PostgreSQL too in CI).

## Commands

- `composer test` (Pest 5; `-- --filter=X` for one test), `composer pint` / `pint:fix`, `composer larastan`.
- `pnpm check` / `check:fix` — oxlint + oxfmt over JS, JSON, YAML and Markdown.
- The gate is `verify` in `.tituskirch-skills.json`.
- Conventional Commits, enforced by commitlint. Never `--no-verify` unless asked. release-please cuts releases on `main`.

## Architecture

- `src/PbacServiceProvider.php` wires everything: `scoped` bindings, the `Gate::before` hook into `PbacGate`, the Octane reset listeners (`pbac.register_octane_reset_listener`).
- A check runs `PbacGate` → `Authorizer` (`PbacAuthorizer`) → `DecisionCache`, keyed on actor + ability + organisation id + target → `RolePermissionQuery::actorHasPermission()`, the single source of truth.
- Organisation scope sits behind `Contracts\OrganisationResolver`, swappable via `pbac.organisation.resolver`.
- Resolve models through `config('pbac.models.*')`, never by class name.
- `HasRoles` stays agnostic to int/uuid/ulid keys: read `pbac.column_names.*` and `pbac.keys.*`.

## Things that are easy to get wrong

- `PbacManager::withOrganisation()` / `withoutOrganisation()` reset the `DecisionCache` on enter **and** exit. That reset is the tenant isolation — keep it.
- A new stateful service is `scoped`, never `singleton`, and implements `Contracts\Resettable` if it caches.
- `PbacAuthorizer::inspect()` returning `null` means "not managed, Laravel decides" (`pbac.gate.manage_existing_permissions_only`) — never deny.
- `pbac.keys.*` must be set before the published migrations run; they read config at run time.
- Migrations are publish-only (`runsMigrations()` off, `vendor:publish --tag=pbac-migrations`). The `bootPackageMigrations()` override skips the work outside the console; keep it.
- Source migrations are named `0001_01_01_<sequence>_<name>.php`; publishing strips the prefix. The sequence is the only carrier of order — pivots must follow `roles`/`permissions` — so a new migration takes the next free number. `MigrationPublishingTest` asserts it.
- Published paths contain a literal `/./`; normalise them in assertions (`normalisePath()`).
- Bindings go in `packageRegistered()`, hooks and listeners in `packageBooted()`. `hasCommands()` registers unconditionally, so a config-gated command belongs in `registerOptionalCommands()`. Booting the provider by hand in a test takes `->register()->boot()`.
- Test setup goes in `tests/TestCase.php` / `tests/Pest.php`. After `migrate:fresh`, call `TestCase::restoreBaselineSchema()`, not the package migrations alone — PostgreSQL enforces the fixture foreign keys.
- `resources/boost/{guidelines,skills}` is consumer-facing and found by path alone: renaming a directory silently drops the package from `boost:install`.
- Boost loads every guideline unversioned and silently blanks one that throws: keep `$assist` calls inside the `GuidelineAssist` allowlist in `BoostResourcesTest`. A `SKILL.md` needs flat `key: value` frontmatter with `name` and `description`, or it is silently dropped.
