# laravel-pbac

[![Latest Version on Packagist](https://img.shields.io/packagist/v/kirchdev/laravel-pbac.svg?style=flat-square)](https://packagist.org/packages/kirchdev/laravel-pbac)
[![Tests](https://github.com/kirchDev/laravel-pbac/actions/workflows/ci.yml/badge.svg)](https://github.com/kirchDev/laravel-pbac/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Policy-based access control for Laravel: roles, permissions, optional organisation-scoped authorization, native `Gate` integration, and a request-scoped decision cache.

- **Roles and permissions** as plain Eloquent models you can swap out.
- **Multi-tenant ready.** Bind roles to an organisation (or any "tenant") with a pluggable resolver, or run global.
- **`Gate::before` integration.** `$user->can('ability')`, `Gate::allows(...)`, and `Gate::inspect(...)` Just Work — abilities resolve through PBAC and fall back to Laravel gates.
- **Decision cache.** Per-request memoization so repeated checks in a single request are cheap.
- **Decision trace.** Opt-in trace of how a decision was reached, redacted in production by default.
- **Octane-aware.** Optional listeners reset scoped state on `RequestTerminated`, `TaskTerminated`, and `TickTerminated`.
- **PHP 8.4 + Laravel 13.**

## Installation

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

## Quick start

Add the `HasRoles` trait to whichever model should be authorizable (usually `User`):

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use KirchDev\Pbac\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

Create roles and permissions, assign them, and check abilities:

```php
use KirchDev\Pbac\Models\Permission;
use KirchDev\Pbac\Models\Role;

$role = Role::create(['name' => 'editor']);
$permission = Permission::create(['name' => 'posts.update']);
$role->permissions()->attach($permission);

$user->assignRole($role);

$user->can('posts.update'); // true
```

## Organisation context (multi-tenant)

Enable organisation scoping in `config/pbac.php`:

```php
'organisation' => [
    'enabled' => true,
    'resolver' => \KirchDev\Pbac\Organisation\DefaultOrganisationResolver::class,
],
```

Then scope checks for the current request:

```php
use KirchDev\Pbac\Facades\Pbac;

Pbac::withOrganisation($organisationId, function () use ($user) {
    return $user->can('members.invite');
});
```

The default resolver is an in-memory holder you set via `Pbac::withOrganisation()` / `Pbac::withoutOrganisation()`. Provide your own implementation of `KirchDev\Pbac\Contracts\OrganisationResolver` (e.g. backed by your tenancy package or a route binding) and wire it via `pbac.organisation.resolver`.

## Configuration highlights

`config/pbac.php` is heavily parameterised — see the file for full inline documentation. The most common knobs:

| Key                                              | What it controls                                                                                                                |
| :----------------------------------------------- | :------------------------------------------------------------------------------------------------------------------------------ |
| `models.*`                                       | Swap any of the four Eloquent models (`Role`, `Permission`, `RoleAssignment`, `RolePermission`).                                |
| `table_names.*`                                  | Override table names if defaults collide with existing tables.                                                                  |
| `keys.*`                                         | Use `id`, `uuid`, or `ulid` for primary keys, model morphs, target morphs, and organisation FK — set before running migrations. |
| `column_names.*`                                 | Pivot and morph key column names (useful for UUID setups).                                                                      |
| `organisation.enabled` / `organisation.resolver` | Toggle multi-tenancy; provide a custom resolver.                                                                                |
| `gate.fallback_to_laravel_gates`                 | Whether unmatched abilities fall back to native Laravel gates.                                                                  |
| `trace.enabled`                                  | Capture a per-decision explanation. Redacted in production by default.                                                          |
| `cache.decision_store`                           | Decision cache backend (`request` by default).                                                                                  |
| `register_octane_reset_listener`                 | Reset scoped state at Octane worker boundaries.                                                                                 |

## Testing

```bash
composer install
composer test       # Pest
composer pint       # Pint (test mode)
composer larastan   # Larastan / PHPStan
```

## Versioning

This package follows [Semantic Versioning](https://semver.org/). See [CHANGELOG.md](CHANGELOG.md) for release notes.

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
