---
name: pbac-development
description: 'Work with kirchdev/laravel-pbac: define roles and permissions, run organisation-scoped authorization checks, understand the Gate fallback, and test all of it.'
---

# PBAC development

Use this when defining roles or permissions, wiring authorization checks, adding multi-tenant
scoping, or debugging why a check returned what it did in an application that uses
`kirchdev/laravel-pbac`.

## Defining roles and permissions

Roles and permissions are plain Eloquent models. Resolve the classes through config so a host
application that swapped them keeps working:

```php
$roleClass = config('pbac.models.role');
$permissionClass = config('pbac.models.permission');

$role = $roleClass::create(['name' => 'editor']);
$role->givePermissionTo('posts.update');
```

- `Role::givePermissionTo()` / `revokePermissionTo()` / `hasPermissionTo()` take a `Permission` or a
  permission name, plus an optional `?Model $target` for a grant that only applies to one record.
- Assignment happens on the authorizable side, through `HasRoles`: `assignRole`, `assignRoles`,
  `removeRole`, `removeRoles`, `syncRoles`, `hasRole`, `roles()`, `permissionNames()`.
- Bootstrapping the first administrator is a data operation, not a migration. The package ships
  `pbac:role:assign` and `pbac:role:revoke` for it:

```bash
php artisan pbac:role:assign 1 superadmin --global
php artisan pbac:role:assign ops@example.com owner --organisation=42 --column=email
php artisan pbac:role:revoke 1 superadmin --global
```

While `pbac.organisation.enabled` is on, exactly one of `--global` / `--organisation=` is
required — scope is never inferred.

## Checking abilities

Always go through Laravel's authorization layer:

```php
$user->can('posts.update');
Gate::allows('posts.update');
Gate::allows('posts.update', $post);   // target-scoped grant
Gate::inspect('posts.update');         // Response, carrying a trace when enabled
```

The check resolves as: `Gate::before` → `PbacGate` → `PbacAuthorizer` → decision cache (keyed by
actor + ability + current organisation id + target morph/id) → `RolePermissionQuery`, which is the
single source of truth for "does this actor have this permission, on this target, in this scope".

## The Gate fallback (the part that surprises people)

`PbacAuthorizer::inspect()` returns `null` — not a denial — when no `Permission` row matches the
ability. That is PBAC saying _"not my ability"_, so Laravel continues to its own gates and policies.

| Config                                       | Effect                                                                                  |
| :------------------------------------------- | :-------------------------------------------------------------------------------------- |
| `pbac.gate.enabled`                          | Turns the whole integration off.                                                        |
| `pbac.gate.before_hook_enabled`              | Registers (or not) the `Gate::before` hook.                                             |
| `pbac.gate.manage_existing_permissions_only` | `true`: unknown abilities are handed back to Laravel. `false`: PBAC answers everything. |
| `pbac.gate.fallback_to_laravel_gates`        | Whether a handed-back ability may still be granted by a native gate.                    |

So a policy that stops firing after installing PBAC usually means the ability now exists as a
`Permission` row, and PBAC is answering it instead.

## Organisation-scoped checks

```php
use KirchDev\Pbac\Facades\Pbac;

Pbac::withOrganisation($organisation->id, function () use ($user) {
    $user->can('members.invite');
});

Pbac::withoutOrganisation(fn () => $user->can('admin.impersonate'));
```

- A role row with a null organisation foreign key is a **global** role; anything else is bound to
  that organisation.
- Both helpers save and restore the previous scope and reset the decision cache on enter and exit.
  Setting the id straight on the resolver skips that reset and leaks decisions between tenants.
- Mutations by name never cross the scope boundary implicitly. Inside a scope,
  `$user->assignRole('owner')` resolves the org-bound row only; reach a global row with
  `global: true`, or with `Pbac::withoutOrganisation()` and a `Role` instance.
- Plug your own tenancy by implementing `KirchDev\Pbac\Contracts\OrganisationResolver`
  (`getOrganisationId`, `setOrganisationId`, `clearOrganisationId`) and pointing
  `pbac.organisation.resolver` at it.

## Debugging a decision

Set `pbac.trace.enabled` to `true` (local and tests only — traces are redacted in production by
default) and read the trace off the response:

```php
$response = Gate::inspect('posts.update', $post);
$response->message();                        // 'pbac.no_matching_role_permission' | …

Pbac::lastDecision()?->trace()->formatted(); // 'role_permission_query(allowed=1, targeted=1) → …'
Pbac::lastDecision()?->trace()->visible();   // structured entries
```

The reason code on the `Response` is available whether or not tracing is on. In production the trace
context is redacted by default; `Pbac::withUnredactedTrace(fn () => ...)` lifts that for one block.
`pbac.trace.log.enabled` writes decisions to the logger instead; `trace.log.on` picks `deny`
(default) or `all`.

## Testing

- Write feature tests against the real `Gate`, not against the query object — the `Gate::before`
  hook is part of the behaviour under test.
- Create the roles and permissions the test needs, assign, then assert `$user->can(...)`.
- For an organisation-scoped assertion, wrap it in `Pbac::withOrganisation()` and assert the
  negative case outside the scope too — that is where cache bleed would show up.
- Grant through the package's own API. The decision cache clears itself on those paths —
  `assignRole`/`removeRole`/`syncRoles`, `Role::givePermissionTo()`/`revokePermissionTo()`, and any
  `saved` or `deleted` on a `Role` or `Permission`. A write that goes around them
  (`$role->permissions()->attach(...)`, a raw insert into a pivot) fires no model event and clears
  nothing, so a check taken before it keeps its cached answer.
- Deleting a `Role` or `Permission` cascades to the pivot tables; deleting a host model does **not**
  (the morph side has no foreign key). Assert that clean-up yourself if the application relies on it.
