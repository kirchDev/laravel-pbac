# Migration guide: `spatie/laravel-permission` → `kirchdev/laravel-pbac`

This guide walks through migrating an existing Laravel application from
[`spatie/laravel-permission`](https://github.com/spatie/laravel-permission) to
[`kirchdev/laravel-pbac`](https://github.com/kirchDev/laravel-pbac).

The two packages overlap conceptually - roles, permissions, an Eloquent pivot
between them, a `HasRoles` trait on the user - but the data model, the API
surface, and the multi-tenancy contract differ in places where a one-to-one
swap is **not** safe. Read the [Conceptual differences](#conceptual-differences)
section before touching code.

> [!IMPORTANT]
> `laravel-pbac` is still a PBAC implementation - abilities flow through
> Laravel's `Gate`, permissions can be target-bound, and decisions are
> traceable. What differs from Spatie is the **assignment model**: every
> permission is granted to a *role*, and users receive permissions only by
> being assigned a role. There is no `User::givePermissionTo()` /
> `User::hasDirectPermission()` equivalent. If your Spatie setup uses direct
> grants, model them as single-user roles (typically `user:{id}`) before the
> cutover. See [Direct user permissions](#direct-user-permissions).

---

## Table of contents

1. [Conceptual differences](#conceptual-differences)
2. [Pre-migration checklist](#pre-migration-checklist)
3. [Step 1 - Install `laravel-pbac` side-by-side](#step-1--install-laravel-pbac-side-by-side)
4. [Step 2 - Reconcile config](#step-2--reconcile-config)
5. [Step 3 - Migrate the database](#step-3--migrate-the-database)
6. [Step 4 - Swap the trait and call sites](#step-4--swap-the-trait-and-call-sites)
7. [Step 5 - Replace teams with organisation scoping](#step-5--replace-teams-with-organisation-scoping)
8. [Step 6 - Authorization, Gates, middleware](#step-6--authorization-gates-middleware)
9. [Step 7 - Tests](#step-7--tests)
10. [Step 8 - Remove `spatie/laravel-permission`](#step-8--remove-spatielaravel-permission)
11. [API cheat sheet](#api-cheat-sheet)
12. [Common pitfalls](#common-pitfalls)

---

## Conceptual differences

| Topic                  | `spatie/laravel-permission`                                          | `kirchdev/laravel-pbac`                                                                |
| :--------------------- | :------------------------------------------------------------------- | :------------------------------------------------------------------------------------- |
| Assignment surface     | Role → Permission **and** User → Permission (direct)                 | Role → Permission only                                                                 |
| Guards                 | Multi-guard (`guard_name` column)                                    | Guard-less - abilities resolve through Laravel's `Gate`, not a guard map               |
| Multi-tenancy          | "Teams" feature, **static** context (`setPermissionsTeamId`)         | "Organisations", **scoped closures** (`Pbac::withOrganisation($id, fn () => …)`)       |
| Target-bound grants    | Not first-class                                                      | First-class - `Role::givePermissionTo($perm, $target)` stores a polymorphic target     |
| Decision cache         | Permission cache (long-lived, must be flushed)                       | Per-request decision cache; auto-invalidated on role/permission writes                 |
| Decision trace         | Not available                                                        | Opt-in `Gate::inspect()` returns a `Response` with `code()` + `message()`              |
| Wildcards (`posts.*`)  | Optional via `enable-wildcard-permission`                            | Not supported - use explicit ability names                                             |
| Octane                 | Manual reset                                                         | Optional listener on `RequestTerminated` / `TaskTerminated` / `TickTerminated`         |
| `HasPermissions` trait | Yes, separate from `HasRoles`                                        | Does not exist - direct grants are out of scope                                        |

### Direct user permissions

Spatie lets you do this:

```php
$user->givePermissionTo('posts.update'); // direct, no role
$user->hasDirectPermission('posts.update');
```

`laravel-pbac` does **not** support this. If you depend on it, model each user's
direct permissions as a private per-user role before the cutover, for example a
role named `user:{user_id}`. The data migration in
[Step 3](#step-3--migrate-the-database) covers this.

---

## Pre-migration checklist

Before you start, audit the codebase for the patterns below - they each have a
mapping but the rewrite is mechanical, not magical:

```bash
# Direct-permission usage (must be modelled as per-user roles)
grep -RIn --include='*.php' 'givePermissionTo\|revokePermissionTo\|hasDirectPermission\|getDirectPermissions' app/

# Spatie-specific helpers without a 1:1 equivalent
grep -RIn --include='*.php' 'syncPermissions\|getAllPermissions\|getPermissionsViaRoles\|hasAllPermissions\|hasAnyPermission' app/

# Guards
grep -RIn --include='*.php' "guard_name" app/ config/

# Teams
grep -RIn --include='*.php' 'setPermissionsTeamId\|getPermissionsTeamId\|team_id' app/

# Wildcards
grep -RIn --include='*.php' "'[a-zA-Z0-9_.-]*\*'" app/

# Middleware in routes
grep -RIn --include='*.php' "->middleware\(\['role:\|->middleware\(\['permission:\|->middleware('role:\|->middleware('permission:" routes/ app/

# Blade directives
grep -RIn --include='*.blade.php' '@role\|@hasrole\|@hasanyrole\|@hasallroles\|@permission\|@can\b\|@unlessrole' resources/views/
```

Capture the hit list - every line is a candidate for [Step 4](#step-4--swap-the-trait-and-call-sites)
or [Step 6](#step-6--authorization-gates-middleware).

---

## Step 1 - Install `laravel-pbac` side-by-side

You will run both packages simultaneously during the migration. Add `laravel-pbac`
without removing Spatie:

```bash
composer require kirchdev/laravel-pbac
php artisan vendor:publish --tag=pbac-config
php artisan vendor:publish --tag=pbac-migrations
```

> [!WARNING]
> The default table names in `laravel-pbac` collide with Spatie's defaults
> (`roles`, `permissions`, `role_has_permissions`, `model_has_roles`). Do **not**
> run `php artisan migrate` yet - first rename the PBAC tables via config so the
> two packages can coexist. See [Step 2](#step-2--reconcile-config).

---

## Step 2 - Reconcile config

While both packages are installed, rename PBAC's tables so they don't shadow
Spatie's. In `config/pbac.php`:

```php
'table_names' => [
    'roles'                => 'pbac_roles',
    'permissions'          => 'pbac_permissions',
    'role_has_permissions' => 'pbac_role_has_permissions',
    'model_has_roles'      => 'pbac_model_has_roles',
],
```

If your existing Spatie schema uses UUID or ULID keys, mirror that in PBAC
**before** migrating - it bakes column types into the migrations:

```php
'keys' => [
    'primary_key_type'       => 'uuid', // or 'ulid' / 'id'
    'model_morph_key_type'   => 'uuid',
    'target_morph_key_type'  => 'uuid',
    'organisation_key_type'  => 'uuid',
],

'column_names' => [
    'model_morph_key'           => 'model_uuid',  // matches your users.uuid pk
    'target_morph_key'          => 'target_uuid',
    'organisation_foreign_key'  => 'organisation_id',
],
```

If you used Spatie's teams feature, enable PBAC's organisation scoping:

```php
'organisation' => [
    'enabled' => true,
    'resolver' => \KirchDev\Pbac\Organisation\DefaultOrganisationResolver::class,
],
```

Gate behaviour is conservative by default - PBAC only answers gate checks whose
ability name matches a row in the `permissions` table, and falls through to
Laravel's native gates / policies otherwise. Keep these defaults during the
migration:

```php
'gate' => [
    'enabled'                          => true,
    'before_hook_enabled'              => true,
    'manage_existing_permissions_only' => true,
    'fallback_to_laravel_gates'        => true,
],
```

Now run the migrations:

```bash
php artisan migrate
```

You should see four new `pbac_*` tables alongside the Spatie tables.

---

## Step 3 - Migrate the database

The package ships an Artisan command that handles the data move idempotently.
It runs as a dry-run by default - pass `--commit` to actually write.

```bash
# Dry-run: validates tables, reports row counts, rolls back at the end.
php artisan pbac:migrate-from-spatie --with-teams

# Persist: same flags + --commit.
php artisan pbac:migrate-from-spatie --with-teams --commit

# Multi-guard setup: prefix abilities with their guard so the guard-less
# PBAC schema doesn't collapse rows with the same name.
php artisan pbac:migrate-from-spatie --guard-prefix --commit

# Single guard only.
php artisan pbac:migrate-from-spatie --guard=web --commit

# Carry direct user permissions over as per-user roles ("user:{id}").
php artisan pbac:migrate-from-spatie \
    --with-teams \
    --collapse-direct-permissions \
    --commit
```

All option defaults match Spatie's default schema. Override per flag if your
installation diverges:

| Flag                              | Default                | Purpose                                                            |
| :-------------------------------- | :--------------------- | :----------------------------------------------------------------- |
| `--connection=<name>`             | app default            | DB connection to read/write on                                     |
| `--roles=<table>`                 | `roles`                | Source Spatie roles table                                          |
| `--permissions=<table>`           | `permissions`          | Source Spatie permissions table                                    |
| `--role-permissions=<table>`      | `role_has_permissions` | Source pivot                                                       |
| `--model-roles=<table>`           | `model_has_roles`      | Source role assignments                                            |
| `--model-permissions=<table>`     | `model_has_permissions`| Source direct grants (empty string disables)                       |
| `--team-column=<column>`          | `team_id`              | Spatie's team column                                               |
| `--guard=<name>`                  | _all guards_           | Filter source rows by `guard_name`                                 |
| `--guard-prefix`                  | off                    | Prefix ability and role names with `<guard>:`                      |
| `--with-teams`                    | off                    | Carry `team_id` over as `organisation_id` on PBAC roles            |
| `--collapse-direct-permissions`   | off                    | Materialise `model_has_permissions` as `user:{id}` roles           |
| `--commit`                        | off                    | Persist changes. Without it the command runs inside a rolled-back transaction. |

The command writes against the **PBAC table names from your config**, so make
sure you have already renamed them as described in [Step 2](#step-2--reconcile-config)
before running it - otherwise it will refuse to run.

### When to hand-roll the migration

The shipped command covers the common case. Write a custom migration class
instead if you need:

- A bespoke `team_id` → `organisation_id` lookup (e.g. a join through a tenant
  mapping table).
- A custom ability-name transform beyond `--guard-prefix` (e.g. renaming
  `posts.update` to `post.update`).
- A non-standard target morph mapping (PBAC writes `target_type = null`,
  `target_id = null` for all migrated grants - Spatie has no target dimension).

A template you can adapt - the long-form equivalent of the Artisan command,
reach for it only when one of the bullet points above applies. The example
below assumes:

- Spatie default tables (`roles`, `permissions`, `model_has_roles`, `role_has_permissions`, `model_has_permissions`).
- PBAC tables renamed to `pbac_*` per [Step 2](#step-2--reconcile-config).
- Spatie's team column is `team_id`; PBAC's organisation column is `organisation_id`.
- Spatie's `guard_name = 'web'` (single guard). For multi-guard setups, namespace permissions per guard (e.g. `web:posts.update`) so they remain unique under PBAC's `(name)` unique index - or run the migration once per guard with a `WHERE guard_name = …` filter and a per-guard ability prefix.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // 1. Permissions  (drop guard_name; uniqueness collapses to name)
            DB::table('permissions')->orderBy('id')->each(function (object $permission): void {
                DB::table('pbac_permissions')->updateOrInsert(
                    ['name' => $permission->name],
                    ['created_at' => $permission->created_at, 'updated_at' => $permission->updated_at],
                );
            });

            // 2. Roles  (team_id → organisation_id; drop guard_name)
            DB::table('roles')->orderBy('id')->each(function (object $role): void {
                DB::table('pbac_roles')->updateOrInsert(
                    ['name' => $role->name, 'organisation_id' => $role->team_id ?? null],
                    ['created_at' => $role->created_at, 'updated_at' => $role->updated_at],
                );
            });

            // Lookup maps (Spatie id → PBAC id), in case ids drift.
            $roleIdMap = DB::table('roles')
                ->get()
                ->mapWithKeys(fn (object $r) => [$r->id => DB::table('pbac_roles')
                    ->where('name', $r->name)
                    ->where('organisation_id', $r->team_id ?? null)
                    ->value('id')])
                ->all();

            $permissionIdMap = DB::table('permissions')
                ->get()
                ->mapWithKeys(fn (object $p) => [$p->id => DB::table('pbac_permissions')
                    ->where('name', $p->name)
                    ->value('id')])
                ->all();

            // 3. Role → permission grants  (no target binding in Spatie)
            DB::table('role_has_permissions')->orderBy('role_id')->each(
                function (object $grant) use ($roleIdMap, $permissionIdMap): void {
                    DB::table('pbac_role_has_permissions')->updateOrInsert([
                        'role_id'       => $roleIdMap[$grant->role_id] ?? null,
                        'permission_id' => $permissionIdMap[$grant->permission_id] ?? null,
                        'target_type'   => null,
                        'target_id'     => null,
                    ]);
                }
            );

            // 4. Role assignments
            DB::table('model_has_roles')->orderBy('role_id')->each(
                function (object $assignment) use ($roleIdMap): void {
                    DB::table('pbac_model_has_roles')->updateOrInsert([
                        'role_id'    => $roleIdMap[$assignment->role_id] ?? null,
                        'model_type' => $assignment->model_type,
                        'model_id'   => $assignment->model_id,
                    ]);
                }
            );

            // 5. Direct user permissions  →  per-user roles
            //    (skip if your app never used direct grants)
            if (DB::getSchemaBuilder()->hasTable('model_has_permissions')) {
                DB::table('model_has_permissions')
                    ->select('model_type', 'model_id')
                    ->distinct()
                    ->orderBy('model_id')
                    ->each(function (object $owner): void {
                        $roleName = sprintf('user:%s', $owner->model_id);
                        $organisationId = DB::table('model_has_permissions')
                            ->where('model_type', $owner->model_type)
                            ->where('model_id', $owner->model_id)
                            ->value('team_id'); // null if teams disabled

                        $roleId = DB::table('pbac_roles')->updateOrInsert(
                            ['name' => $roleName, 'organisation_id' => $organisationId],
                            ['created_at' => now(), 'updated_at' => now()],
                        );

                        $roleId = DB::table('pbac_roles')
                            ->where('name', $roleName)
                            ->where('organisation_id', $organisationId)
                            ->value('id');

                        DB::table('pbac_model_has_roles')->updateOrInsert([
                            'role_id'    => $roleId,
                            'model_type' => $owner->model_type,
                            'model_id'   => $owner->model_id,
                        ]);

                        DB::table('model_has_permissions')
                            ->where('model_type', $owner->model_type)
                            ->where('model_id', $owner->model_id)
                            ->get()
                            ->each(function (object $grant) use ($roleId): void {
                                $permissionId = DB::table('pbac_permissions')
                                    ->where('name', DB::table('permissions')
                                        ->where('id', $grant->permission_id)
                                        ->value('name'))
                                    ->value('id');

                                DB::table('pbac_role_has_permissions')->updateOrInsert([
                                    'role_id'       => $roleId,
                                    'permission_id' => $permissionId,
                                    'target_type'   => null,
                                    'target_id'     => null,
                                ]);
                            });
                    });
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('pbac_role_has_permissions')->truncate();
            DB::table('pbac_model_has_roles')->truncate();
            DB::table('pbac_permissions')->truncate();
            DB::table('pbac_roles')->truncate();
        });
    }
};
```

Always run a verification query in staging before flipping production traffic:

```sql
-- Row counts must match (modulo guard collapsing and per-user role expansion)
SELECT 'spatie.permissions' src, COUNT(*) FROM permissions
UNION ALL SELECT 'pbac.permissions',  COUNT(*) FROM pbac_permissions
UNION ALL SELECT 'spatie.roles',      COUNT(*) FROM roles
UNION ALL SELECT 'pbac.roles',        COUNT(*) FROM pbac_roles
UNION ALL SELECT 'spatie.role_has_permissions',  COUNT(*) FROM role_has_permissions
UNION ALL SELECT 'pbac.role_has_permissions',    COUNT(*) FROM pbac_role_has_permissions
UNION ALL SELECT 'spatie.model_has_roles',       COUNT(*) FROM model_has_roles
UNION ALL SELECT 'pbac.model_has_roles',         COUNT(*) FROM pbac_model_has_roles;
```

---

## Step 4 - Swap the trait and call sites

Replace the trait import on every authorizable model:

```diff
- use Spatie\Permission\Traits\HasRoles;
+ use KirchDev\Pbac\Traits\HasRoles;

  class User extends Authenticatable
  {
      use HasRoles;
  }
```

### Role and permission management

| What you want                  | Spatie                                              | PBAC                                                                |
| :----------------------------- | :-------------------------------------------------- | :------------------------------------------------------------------ |
| Create a role                  | `Role::create(['name' => 'editor'])`                | `Role::create(['name' => 'editor'])`                                |
| Create a role for a tenant     | `Role::create(['name' => 'editor', 'team_id' => 1])`| `Role::create(['name' => 'editor', 'organisation_id' => 1])`        |
| Create a permission            | `Permission::create(['name' => 'posts.update'])`    | `Permission::create(['name' => 'posts.update'])`                    |
| Find or create                 | `Role::findOrCreate('editor', $guard)`              | `Role::findOrCreate('editor', $organisationId)`                     |
| Assign role to user            | `$user->assignRole($role)` / `$user->assignRole('a','b')` | `$user->assignRole($role)` / `$user->assignRoles('a', 'b')`         |
| Remove role from user          | `$user->removeRole($role)`                          | `$user->removeRole($role)` / `$user->removeRoles('a', 'b')`         |
| Sync roles                     | `$user->syncRoles(['a', 'b'])`                      | `$user->syncRoles(['a', 'b'])`                                      |
| Check role                     | `$user->hasRole('editor')`                          | `$user->hasRole('editor')`                                          |
| Grant permission to role       | `$role->givePermissionTo('posts.update')`           | `$role->givePermissionTo('posts.update')`                           |
| Target-scoped grant            | _(not first-class)_                                 | `$role->givePermissionTo('posts.update', $post)`                    |
| Revoke from role               | `$role->revokePermissionTo('posts.update')`         | `$role->revokePermissionTo('posts.update', $target = null)`         |
| List a user's permission names | `$user->getPermissionNames()`                       | `$user->permissionNames()`                                          |
| Direct user permission         | `$user->givePermissionTo('foo')`                    | _(use a per-user role; see Step 3)_                                 |

> [!NOTE]
> `assignRoles()` / `removeRoles()` are variadic and accept any mix of
> `Role`, `string`, and `int` arguments. `syncRoles()` accepts any iterable
> (array, generator, Collection). All three reset the decision cache once at
> the end, so they're safe to use in bulk paths.

### Lookups that don't return identical types

- `User::getPermissionNames(): Collection<string>` becomes
  `User::permissionNames(): array<string>`. If your code calls `Collection`
  methods on the result, wrap it: `collect($user->permissionNames())`.
- PBAC has no `getAllPermissions()` / `getPermissionsViaRoles()` /
  `hasAllPermissions()` / `hasAnyPermission()`. The replacement is `Gate`
  (`$user->can('posts.update')`) or `collect($user->permissionNames())->intersect($names)`.
- `Role::findByName($name)` requires an explicit organisation id when
  organisation scoping is enabled - the active resolver context is **not**
  consulted by the static finder.

---

## Step 5 - Replace teams with organisation scoping

Spatie's teams feature is process-wide and stateful:

```php
// Spatie
app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($teamId);
// every subsequent role/permission check runs against $teamId until cleared
```

PBAC is closure-scoped and exception-safe:

```php
use KirchDev\Pbac\Facades\Pbac;

Pbac::withOrganisation($organisation->id, function () use ($user) {
    $user->can('members.invite');   // org-scoped check
    $user->assignRole('editor');     // org-scoped role lookup
});                                  // previous scope restored, even on throw

Pbac::withoutOrganisation(fn () => $user->can('admin.impersonate'));
```

Where to call `withOrganisation()`:

- **Routes**: prefer a route-bound resolver (see below) over per-controller wrapping.
- **Jobs / queue listeners**: wrap `handle()` - `Pbac::withOrganisation($this->orgId, fn () => …)`.
- **Console commands**: wrap the body of `handle()`.

### Route-bound resolver

If your app routes already carry the tenant (e.g. `/orgs/{organisation}/…`),
replace the default resolver so PBAC reads it automatically:

```php
namespace App\Auth;

use KirchDev\Pbac\Contracts\OrganisationResolver;

final class RouteOrganisationResolver implements OrganisationResolver
{
    private int|string|null $override = null;
    private bool $hasOverride = false;

    public function getOrganisationId(): int|string|null
    {
        if ($this->hasOverride) {
            return $this->override;
        }

        return request()->route('organisation')?->getKey();
    }

    public function setOrganisationId(int|string|null $id): void
    {
        $this->override = $id;
        $this->hasOverride = true;
    }

    public function clearOrganisationId(): void
    {
        $this->override = null;
        $this->hasOverride = false;
    }
}
```

```php
// config/pbac.php
'organisation' => [
    'enabled'  => true,
    'resolver' => \App\Auth\RouteOrganisationResolver::class,
],
```

After this, `$user->can('members.invite')` inside a route handler resolves
against the route-bound organisation without explicit `withOrganisation()`.

---

## Step 6 - Authorization, Gates, middleware

### `$user->can()` and `Gate::allows()`

These already work in PBAC - the service provider installs a `Gate::before`
hook that resolves the ability against `permissions.name`, runs through the
decision cache, and falls through to native Laravel gates / policies when no
permission row matches (`gate.fallback_to_laravel_gates = true`).

```php
$user->can('posts.update');           // PBAC handles it if the permission exists
$user->can('posts.update', $post);     // target-scoped check
Gate::allows('posts.update', $post);   // same plumbing
Gate::inspect('posts.update', $post);  // Response with code() / message()
```

### Middleware

Spatie ships `role`, `permission`, `role_or_permission` middleware. PBAC does
not - use Laravel's built-in `can` middleware instead:

```diff
- Route::get('/posts/{post}/edit', …)->middleware('permission:posts.update');
+ Route::get('/posts/{post}/edit', …)->middleware('can:posts.update,post');

- Route::middleware('role:editor')->group(…);
+ // Roles are not abilities. Either expose a synthetic ability that maps to a role,
+ // or guard with a custom middleware:
+ Route::middleware('role:editor')->group(…); // → see App\Http\Middleware\EnsureRole
```

If you genuinely need a "role middleware", write a small one:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        foreach ($roles as $role) {
            if ($user?->hasRole($role)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
```

### Blade

PBAC has no custom Blade directives. Use `@can` and `@cannot`, which already
flow through `Gate` and therefore through PBAC:

```diff
- @role('editor') … @endrole
+ @if (auth()->user()?->hasRole('editor')) … @endif

- @permission('posts.update') … @endpermission
+ @can('posts.update') … @endcan

- @hasanyrole('editor|reviewer') … @endhasanyrole
+ @if (auth()->user()?->hasRole('editor') || auth()->user()?->hasRole('reviewer')) … @endif
```

---

## Step 7 - Tests

Update factory / seeder usage to drop `guard_name`:

```diff
- Role::create(['name' => 'editor', 'guard_name' => 'web'])
+ Role::create(['name' => 'editor'])
```

If you assert on cache behaviour, remove explicit
`app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions()`
calls - PBAC invalidates its decision cache automatically on model saves and on
scope enter/exit. There is no persistent permission cache in PBAC's default
config.

For decision-trace assertions:

```php
'trace' => ['enabled' => true, 'redact_in_production' => true],
```

```php
$response = Gate::inspect('posts.update', $post);

expect($response->allowed())->toBeTrue();
expect($response->code())->toBe('pbac.granted'); // or 'pbac.role_permission_allowed'
```

---

## Step 8 - Remove `spatie/laravel-permission`

Once application code, routes, views, jobs, and tests are green against PBAC:

1. Drop the Spatie tables (`roles`, `permissions`, `role_has_permissions`,
   `model_has_roles`, `model_has_permissions`) in a follow-up migration.
2. Optionally rename PBAC tables back to the canonical defaults
   (`roles`, `permissions`, `role_has_permissions`, `model_has_roles`) - update
   `config/pbac.php` `table_names` and write a `Schema::rename()` migration.
3. Remove the package:

   ```bash
   composer remove spatie/laravel-permission
   ```

4. Delete `config/permission.php` and any `Spatie\Permission\…` use statements.

---

## API cheat sheet

```php
// Models
use KirchDev\Pbac\Models\{Role, Permission};
use KirchDev\Pbac\Traits\HasRoles;
use KirchDev\Pbac\Facades\Pbac;

// Roles & permissions
$role = Role::findOrCreate('editor', $organisationId = null);
$perm = Permission::findOrCreate('posts.update');

$role->givePermissionTo($perm);              // broad grant
$role->givePermissionTo($perm, $post);       // target-bound grant
$role->revokePermissionTo($perm, $post);
$role->hasPermissionTo($perm, $post);

// Assignments
$user->assignRole($role);                    // accepts Role | string | int
$user->assignRoles($a, 'editor', 42);        // variadic bulk
$user->removeRole($role);
$user->removeRoles($a, 'editor');            // variadic bulk
$user->syncRoles(['editor', 'reviewer']);    // iterable; replaces the active set
$user->hasRole('editor');
$user->permissionNames();                    // array<string>

// Gate
$user->can('posts.update', $post);
Gate::allows('posts.update', $post);
Gate::inspect('posts.update', $post)->message();

// Tenant scoping
Pbac::withOrganisation($org->id, fn () => $user->can('members.invite'));
Pbac::withoutOrganisation(fn () => $user->can('admin.impersonate'));
Pbac::currentOrganisationId();
Pbac::reset();
```

---

## Common pitfalls

- **Forgetting to set `keys.*` before `migrate`.** Primary key types are baked
  into the schema. Change them in `config/pbac.php` _before_ running migrations,
  or write an application-owned migration to alter columns afterwards.
- **Relying on `guard_name`.** PBAC has no concept of guards. If the same
  ability name has different semantics across guards, prefix the ability
  (`web:posts.update`, `api:posts.update`) and migrate accordingly.
- **Static team context inside queued jobs.** `setPermissionsTeamId()` survives
  across requests but **not** across queue workers. Replace it with
  `Pbac::withOrganisation($this->organisationId, fn () => …)` in `handle()` so
  the scope is explicit and exception-safe.
- **Wildcards.** Spatie's optional wildcard mode (`posts.*`) has no PBAC
  equivalent. Either expand to explicit abilities, or wrap with a custom
  `Gate::define()` that checks a prefix against `permissionNames()`.
- **`$user->givePermissionTo()` after migration.** It compiles (PHP doesn't
  catch it) only because the method existed on the previous trait - it does
  **not** exist on PBAC's `HasRoles`. The audit grep in
  [Pre-migration checklist](#pre-migration-checklist) catches this; CI should
  re-run it as a guardrail until the cutover is done.
- **Octane workers.** If you run Octane, set
  `PBAC_REGISTER_OCTANE_RESET_LISTENER=true` so the per-request decision cache
  and organisation context reset at worker boundaries.

---

If you hit a case this guide doesn't cover, please
[open an issue](https://github.com/kirchDev/laravel-pbac/issues) with the
Spatie snippet and the behaviour you need - the rough edges of the migration
path are exactly what we want to document.
