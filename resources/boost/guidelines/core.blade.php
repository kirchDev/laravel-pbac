# Laravel PBAC

`kirchdev/laravel-pbac` adds policy-based access control: roles, permissions, organisation scoping,
native `Gate` integration and a request-scoped decision cache. Everything is configured in
`config/pbac.php`.

## Setup order (do not reorder)
- Publish the config first: `{{ $assist->artisanCommand('vendor:publish --tag=pbac-config') }}`.
- Set `pbac.keys.*` (`id` / `uuid` / `ulid`) in it now — the migrations read the config at run time
  and bake the key types into the schema, so this has to happen before the `migrate` below.
  Changing them afterwards needs an application-owned migration.
- The package ships migrations but never loads them. Publish them once, then migrate:
  `{{ $assist->artisanCommand('vendor:publish --tag=pbac-migrations') }}` and
  `{{ $assist->artisanCommand('migrate') }}`.
- Add the `KirchDev\Pbac\Traits\HasRoles` trait to the authorizable model (usually `User`).

## Checking abilities
- Check through Laravel: `$user->can('posts.update')`, `Gate::allows(...)`, `Gate::inspect(...)`.
  PBAC answers via a `Gate::before` hook. Never read `model_has_roles` / `role_has_permissions`
  directly to decide access.
- An ability with **no matching `Permission` row is not a denial**. PBAC returns "not my ability" and
  Laravel falls back to its own gates and policies — see `pbac.gate.manage_existing_permissions_only`
  and `pbac.gate.fallback_to_laravel_gates`.

## Organisation scope
- Never set the organisation on the resolver by hand. Wrap the checks instead:
  `Pbac::withOrganisation($organisation->id, fn () => $user->can('members.invite'))` and
  `Pbac::withoutOrganisation(fn () => $user->can('admin.impersonate'))`. Both reset the decision
  cache on enter *and* exit, which is what stops decisions bleeding across tenants.
- While scoping is enabled, mutating a role **by name** refuses to resolve a global role unless the
  intent is explicit: `$user->assignRole('superadmin', global: true)` (same for `removeRole`,
  `syncRoles`, `hasRole`).

## Extending it
- Resolve the models through `config('pbac.models.role')`, `...permission`, `...role_assignment`,
  `...role_permission` — all four are swappable. Never `new`, `::query()` or `::find()` on
  `KirchDev\Pbac\Models\*`: the application's own subclass carries its key generation, casts and
  model events, and an application without a morph map stores the class it resolved, so a role
  assignment written through the packaged class names a different class than every row beside it
  and quietly stops resolving — with nothing raised anywhere.
- That rule is about resolution, not about type declarations. A type-hint or a `@param`/`@return`
  constructs nothing, and an application's subclass satisfies a hint on the packaged class, so
  neither needs changing. Narrow one where the generics carry the type: declare
  `{{ '@'.'use' }} HasRoles<Role, RoleAssignment>` on the authorizable, and
  `{{ '@'.'extends' }} Role<Permission, RolePermission>` on a model override, naming your own
  classes — `roles()` and `permissions()` then keep them. Never add an annotation purely
  to narrow a type.
- Custom tenancy implements `KirchDev\Pbac\Contracts\OrganisationResolver` and is wired via
  `pbac.organisation.resolver`.
- Bind any PBAC-adjacent service as `scoped`, not `singleton` (Octane keeps workers alive), and let
  it implement `KirchDev\Pbac\Contracts\Resettable` when it caches anything per request.
