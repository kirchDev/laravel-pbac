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

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=pbac-migrations
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=pbac-config
```

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

## 🔍 Decision trace

Wondering _why_ a permission check returned what it did? Turn on tracing:

```php
// config/pbac.php
'trace' => [
    'enabled' => true,
    'redact_in_production' => true,
],
```

```php
$response = Gate::inspect('posts.update', $post);

$response->code();    // 'pbac.granted'
$response->message(); // 'role:editor → permission:posts.update (org-scoped)'
```

Production environments redact role names and target details by default — opt-in to surface them per-route if you need.

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

## 🧪 Testing

```bash
composer install
composer test       # Pest 4
composer pint       # Laravel Pint (test mode)
composer larastan   # Larastan / PHPStan
```

The test suite runs via Testbench + in-memory SQLite — no host app required.

## 🤝 Contributing

PRs welcome. Conventional Commits required (enforced via commitlint). Husky runs Pint + Larastan + oxlint + oxfmt on `git commit`, so you can mostly forget about style.

> [!TIP]
> Run `pnpm check:fix` (Node tooling) and `composer pint:fix` (PHP) before pushing — CI will catch what husky missed.

## 🛣️ Versioning

[Semantic Versioning](https://semver.org/). Release notes in [CHANGELOG.md](CHANGELOG.md) — managed by [release-please](https://github.com/googleapis/release-please).

## 📄 License

[MIT](LICENSE) © [Titus Kirch](https://github.com/TitusKirch/) / [IT-Dienstleistungen Titus Kirch](https://kirch.dev)
