# Laravel PBAC

`kirchdev/laravel-pbac` adds policy-based access control: roles, permissions, organisation scoping,
native `Gate` integration and a request-scoped decision cache. Everything is configured in
`config/pbac.php`.

## Setup order (do not reorder)
- The package ships migrations but never loads them. Publish them once, then migrate:
  `{{ $assist->artisanCommand('vendor:publish --tag=pbac-migrations') }}` and
  `{{ $assist->artisanCommand('migrate') }}`.
- Set `pbac.keys.*` (`id` / `uuid` / `ulid`) **before** that `migrate` run — the migrations read the
  config at run time and bake the key types into the schema. Changing them later needs an
  application-owned migration.
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
  `...role_permission` — never reference `KirchDev\Pbac\Models\*` directly. All four are swappable.
- Custom tenancy implements `KirchDev\Pbac\Contracts\OrganisationResolver` and is wired via
  `pbac.organisation.resolver`.
- Bind any PBAC-adjacent service as `scoped`, not `singleton` (Octane keeps workers alive), and let
  it implement `KirchDev\Pbac\Contracts\Resettable` when it caches anything per request.
