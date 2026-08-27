<div align="center">

# 🛡️ laravel-pbac

**Policy-based access control for Laravel — roles, permissions, multi-tenant scoping, decision tracing, and native `Gate` integration.**

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kirchdev/laravel-pbac.svg?style=flat-square&color=4f46e5)](https://packagist.org/packages/kirchdev/laravel-pbac)
[![Total Downloads](https://img.shields.io/packagist/dt/kirchdev/laravel-pbac.svg?style=flat-square&color=4f46e5)](https://packagist.org/packages/kirchdev/laravel-pbac)
[![Tests](https://img.shields.io/github/actions/workflow/status/kirchDev/laravel-pbac/ci.yml?branch=main&style=flat-square&label=tests)](https://github.com/kirchDev/laravel-pbac/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/kirchdev/laravel-pbac/php?style=flat-square&color=8993be)](https://packagist.org/packages/kirchdev/laravel-pbac)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/kirchdev/laravel-pbac/illuminate%2Fsupport?style=flat-square&label=laravel&color=ff2d20)](https://packagist.org/packages/kirchdev/laravel-pbac)
[![License: MIT](https://img.shields.io/packagist/l/kirchdev/laravel-pbac.svg?style=flat-square&color=10b981)](LICENSE)

</div>

---

```php
Pbac::withOrganisation($org->id, fn () => $user->can('members.invite')); // ✅
```

That's it. Tenant-aware authorization in one line, native Laravel `Gate` semantics, no manual scope plumbing.

## ✨ Features

- **🎭 Roles & permissions** — plain Eloquent models you can swap out for your own (UUID / ULID / int keys).
- **🏢 Organisation/tenant scoping** — first-class, with a pluggable `OrganisationResolver`. Scopes never bleed across tenants.
- **🚪 Native `Gate` integration** — `$user->can()`, `Gate::allows()`, `Gate::inspect()` all Just Work, with fallback to native Laravel gates.
- **⚡ Per-request decision cache** — repeated checks within a request are free. Auto-invalidates on role/permission mutations.
- **🔍 Decision trace** — opt-in audit trail of _why_ a check returned what it did. Redacted in production by default.
- **🚀 Octane-aware** — optional reset listeners on `RequestTerminated`, `TaskTerminated`, `TickTerminated`. No stale state across requests.
- **🧰 Heavy configuration** — model / table / column / key types all overridable. UUID setups supported out of the box.
- **🧪 Library-grade** — Pest 4 + Testbench, no host app needed.

## 📦 Installation

```bash
composer require kirchdev/laravel-pbac
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=pbac-config
```

Publish and run the migrations. This one-time publish is **required** — the package ships the
migrations but never loads them, so nothing reaches your schema until you have a copy of your own:

```bash
php artisan vendor:publish --tag=pbac-migrations
php artisan migrate
```

> [!IMPORTANT]
> Set `pbac.keys.*` in the published config **before** you run `migrate` — the migrations read that
> config at run time and bake the key types into the schema.

## 🚀 Quick start

Add the `HasRoles` trait to whichever model should be authorizable:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use KirchDev\Pbac\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

Create roles, attach permissions, assign, check:

```php
use KirchDev\Pbac\Models\{Permission, Role};

$role = Role::create(['name' => 'editor']);
$role->permissions()->attach(
    Permission::create(['name' => 'posts.update'])
);

$user->assignRole($role);

$user->can('posts.update');     // ✅ true
Gate::allows('posts.update');   // ✅ true (same plumbing)
Gate::inspect('posts.update');  // ✅ Response with trace (if enabled)
```

## 🏢 Multi-tenant authorization

Enable organisation scoping:

```php
// config/pbac.php
'organisation' => [
    'enabled' => true,
    'resolver' => \KirchDev\Pbac\Organisation\DefaultOrganisationResolver::class,
],
```

Scope authorization for the current request:

```php
use KirchDev\Pbac\Facades\Pbac;

Pbac::withOrganisation($organisation->id, function () use ($user) {
    $user->can('members.invite'); // checked against org-bound roles
    $user->can('billing.view');   // …same scope
});

// Global checks — no active org
Pbac::withoutOrganisation(fn () => $user->can('admin.impersonate'));
```

The decision cache resets on scope enter/exit, so checks **never bleed across tenants**.

### Assigning global roles when org scoping is enabled

To prevent silent mis-targeting, role **mutations** by name refuse to resolve a global role unless the caller signals intent. Pass `global: true`, or wrap the call in `Pbac::withoutOrganisation()` and assign by `Role` instance:

```php
$user->assignRole('superadmin', global: true);
$user->removeRole('superadmin', global: true);
$user->syncRoles(['superadmin', 'support_lead'], global: true);
$user->hasRole('superadmin', global: true);

// Equivalent for arbitrary mixed batches:
$role = Role::findOrCreate('superadmin');
Pbac::withoutOrganisation(fn () => $user->assignRole($role));
```

Inside an active organisation scope, `$user->assignRole('owner')` resolves the org-scoped row only — global rows with the same name are deliberately invisible to mutations without the explicit flag.

Bring your own resolver (e.g. backed by a tenancy package or route binding):

```php
final class TenantRouteResolver implements \KirchDev\Pbac\Contracts\OrganisationResolver
{
    public function getOrganisationId(): int|string|null
    {
        return request()->route('organisation')?->getKey();
    }
    // …setOrganisationId, clearOrganisationId
}
```

Wire it via `pbac.organisation.resolver`.

## 🧰 Console commands

Bootstrapping the first administrator is a data operation, not a migration. Two always-registered commands cover it:

```bash
# Grant a global role
php artisan pbac:role:assign 1 superadmin --global

# Grant an organisation-bound role, looking the target up by email
php artisan pbac:role:assign ops@example.com owner --organisation=42 --column=email

# Take it away again
php artisan pbac:role:revoke 1 superadmin --global
```

| Option           | What it does                                                                                                     |
| :--------------- | :--------------------------------------------------------------------------------------------------------------- |
| `--global`       | Target the global role. Required when organisation scoping is on and the role is not organisation-bound.         |
| `--organisation` | Target the role bound to this organisation. Mutually exclusive with `--global`.                                  |
| `--model`        | Fully qualified target model class. Overrides `pbac.models.default_model` and the default guard's auth provider. |
| `--column`       | Column to look the identifier up on. Defaults to the primary key.                                                |

> [!IMPORTANT]
> **Scope is never inferred.** While `pbac.organisation.enabled` is on, exactly one of `--global` / `--organisation=` is required — a forgotten flag must not silently grant a global role in production. With the feature off, neither flag applies.

Both commands write through the `HasRoles` trait, so they inherit its strict scope resolution and decision-cache reset; a target model that does not use the trait is rejected rather than written at pivot level. After the change they print the target's resulting role set, which is the verification a bootstrap call needs. An ambiguous `--column` lookup is refused rather than resolved to the first match.

## 🔍 Decision trace

Wondering _why_ a permission check returned what it did? Turn on tracing:

```php
// config/pbac.php
'trace' => [
    'enabled' => true,
    // null → auto: redact when APP_ENV=production AND APP_DEBUG=false
    // true|false → forced
    'redact' => null,
    'log' => [
        'enabled' => false,           // structured logging via Laravel's logger
        'channel' => null,            // null = default channel
        'level' => 'info',
        'on' => 'deny',               // or 'all'
    ],
],
```

`Gate::inspect()` carries the decision's reason code via `Response::message()`:

```php
$response = Gate::inspect('posts.update', $post);

$response->allowed();  // bool
$response->message();  // 'pbac.role_permission_allowed' | 'pbac.no_matching_role_permission' | …
```

For the human-readable trace, reach for the last decision through the `Pbac` facade:

```php
use KirchDev\Pbac\Facades\Pbac;

$user->can('posts.update', $post);

Pbac::lastDecision()?->trace()->visible();   // structured entries
Pbac::lastDecision()?->trace()->formatted(); // 'role_permission_query(allowed=1, targeted=1) → role_permission_allowed'
```

Production redacts trace context arrays by default (step names stay; values are stripped). Opt in to the full trace per request when you need it — e.g. for an admin debug route:

```php
Pbac::withUnredactedTrace(function () use ($user, $post) {
    $user->can('posts.update', $post);

    return Pbac::lastDecision()?->trace()->formatted(); // unredacted
});
```

## 🧹 Cascade behaviour on delete

Foreign keys are deliberately set to `ON DELETE CASCADE` so the indexes never carry stale grants or assignments. Mark this on your operational checklist:

| When you delete…               | These rows go away automatically                                            |
| :----------------------------- | :-------------------------------------------------------------------------- |
| A `Role`                       | All `role_has_permissions` rows for that role + all `model_has_roles` rows. |
| A `Permission`                 | All `role_has_permissions` rows referencing it.                             |
| A host model (e.g. `User`) row | **Not** automatic. `model_has_roles` rows on the morph side are orphaned.   |

The host-model side is polymorphic (`model_type` + `model_id`), so no FK enforces it. Hook your model's `deleting`/`deleted` events or run a periodic prune job if user/team deletions are part of your normal flow. If you need an audit trail of historical grants/assignments, capture it **before** deletion — once the cascade fires, the rows are gone.

## ⚙️ Configuration highlights

`config/pbac.php` is heavily parameterised — see the file for inline docs. Most common knobs:

| Key                                  | What it controls                                                                                         |
| :----------------------------------- | :------------------------------------------------------------------------------------------------------- |
| `models.*`                           | Swap any of the 4 Eloquent models (Role / Permission / RoleAssignment / RolePermission).                 |
| `table_names.*`                      | Override defaults if they collide with existing tables.                                                  |
| `keys.*`                             | `id` / `uuid` / `ulid` for primary keys, model morphs, target morphs, org FK. Set **before** migrations. |
| `column_names.*`                     | Pivot and morph key column names (handy for UUID setups).                                                |
| `organisation.enabled` / `.resolver` | Toggle multi-tenancy, plug a custom resolver.                                                            |
| `gate.fallback_to_laravel_gates`     | Whether unmatched abilities fall back to native Laravel gates.                                           |
| `trace.enabled`                      | Capture per-decision explanations. Redacted in prod by default.                                          |
| `cache.decision_store`               | Decision cache backend (`request` by default).                                                           |
| `register_octane_reset_listener`     | Reset scoped state at Octane worker boundaries.                                                          |

## 🔁 Migrating from `spatie/laravel-permission`

Coming from `spatie/laravel-permission`? See the dedicated guide for schema, API,
and multi-tenancy differences plus a copy-pasteable data-migration script:
[docs/migration-from-spatie-laravel-permission.md](docs/migration-from-spatie-laravel-permission.md).

## 🧪 Testing

```bash
composer install
composer test       # Pest 4
composer pint       # Laravel Pint (test mode)
composer larastan   # Larastan / PHPStan
```

The test suite runs via Testbench + in-memory SQLite — no host app required.

## ⬆️ Upgrading

### Migrations are publish-only (breaking)

The service provider no longer calls `loadMigrationsFrom()`. The migrations reach your application
through the `pbac-migrations` tag only, so schema changes are reviewed in your repository instead of
arriving with a `composer update`.

If you have already published them, this is a no-op — your copies in `database/migrations` were
already shadowing the package's. Otherwise, publish them once before your next deploy:

```bash
php artisan vendor:publish --tag=pbac-migrations
```

The filenames are unchanged, so migrations you have already run stay recorded as run and
`php artisan migrate` finds nothing new.

## 🤝 Contributing

PRs welcome. Conventional Commits required (enforced via commitlint). Husky runs Pint + Larastan + oxlint + oxfmt on `git commit`, so you can mostly forget about style.

> [!TIP]
> Run `pnpm check:fix` (Node tooling) and `composer pint:fix` (PHP) before pushing — CI will catch what husky missed.

## 🛣️ Versioning

[Semantic Versioning](https://semver.org/). Release notes in [CHANGELOG.md](CHANGELOG.md) — managed by [release-please](https://github.com/googleapis/release-please).

## 📄 License

[MIT](LICENSE) © [Titus Kirch](https://github.com/TitusKirch/) / [IT-Dienstleistungen Titus Kirch](https://kirch.dev)
