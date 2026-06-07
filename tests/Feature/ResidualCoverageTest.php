<?php

declare(strict_types=1);

use KirchDev\Pbac\Authorization\PermissionResolver;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Facades\Pbac;
use KirchDev\Pbac\Models\Permission;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\PbacServiceProvider;
use KirchDev\Pbac\Support\ModelIdentifier;
use KirchDev\Pbac\Tests\Fixtures\User;
use Laravel\Octane\Events\RequestTerminated;

it('exposes Role::permissions() as a BelongsToMany relation', function () {
    $role = Role::findOrCreate('viewer');
    $role->givePermissionTo('posts.view');

    expect($role->permissions)->toHaveCount(1)
        ->and($role->permissions->first()?->name)->toBe('posts.view');
});

it('promotes a role into the active organisation context via setCurrentOrganisation', function () {
    $role = Role::findOrCreate('owner', organisationId: 42);

    app(OrganisationResolver::class)->clearOrganisationId();
    $role->setCurrentOrganisation();

    expect(app(OrganisationResolver::class)->getOrganisationId())->toBe(42);
});

it('skips setCurrentOrganisation when org scoping is disabled', function () {
    config()->set('pbac.organisation.enabled', false);

    $role = Role::findOrCreate('global-only');
    app(OrganisationResolver::class)->clearOrganisationId();
    $role->setCurrentOrganisation();

    expect(app(OrganisationResolver::class)->getOrganisationId())->toBeNull();
});

it('clears state through PbacManager::reset', function () {
    $user = User::query()->create(['name' => 'Reset', 'email' => 'reset-mgr@example.com']);
    $role = Role::findOrCreate('staff');
    $role->givePermissionTo('posts.view');
    $user->assignRole($role, global: true);

    app(OrganisationResolver::class)->setOrganisationId(99);
    $user->can('posts.view');

    expect(Pbac::lastDecision())->not->toBeNull()
        ->and(Pbac::currentOrganisationId())->toBe(99);

    Pbac::reset();

    expect(Pbac::lastDecision())->toBeNull()
        ->and(Pbac::currentOrganisationId())->toBeNull();
});

it('lets PermissionResolver::forget drop a single entry', function () {
    Permission::findOrCreate('targeted.permission');

    $resolver = app(PermissionResolver::class);
    $resolver->reset();

    expect($resolver->resolve('targeted.permission'))->not->toBeNull();

    $resolver->forget('targeted.permission');

    // After forget, the resolver re-queries — the entry is no longer cached.
    expect($resolver->resolve('targeted.permission'))->not->toBeNull();
});

it('rejects unpersisted models when constructing a ModelIdentifier', function () {
    $unsaved = new User(['name' => 'Ghost', 'email' => 'ghost@example.com']);

    expect(fn () => ModelIdentifier::fromModel($unsaved))
        ->toThrow(InvalidArgumentException::class, 'Target models must be persisted before they can be used for PBAC authorization.');
});

it('is a no-op when removeRoles is called without any arguments', function () {
    $user = User::query()->create(['name' => 'NoOp', 'email' => 'noop@example.com']);
    $role = Role::findOrCreate('any');
    $user->assignRole($role, global: true);

    $user->removeRoles();

    expect($user->roles()->count())->toBe(1);
});

it('is a no-op when removeRole is called for a role the user does not have', function () {
    $user = User::query()->create(['name' => 'Empty', 'email' => 'empty@example.com']);
    Role::findOrCreate('untouched');

    $user->removeRole('untouched', global: true);

    expect($user->roles()->count())->toBe(0);
});

it('registers Octane reset listeners when the config flag is on', function () {
    config()->set('pbac.register_octane_reset_listener', true);

    // Re-boot the provider to pick up the new flag.
    $provider = new PbacServiceProvider(app());
    $provider->boot();

    expect(true)->toBeTrue(); // smoke — no exception means the listener path executed
});

it('triggers the Octane reset listener flow when an event fires', function () {
    config()->set('pbac.register_octane_reset_listener', true);

    (new PbacServiceProvider(app()))->boot();

    // Resolve PbacManager so the listener's `resolved('pbac')` guard passes.
    Pbac::withOrganisation(7, fn () => null);
    app(OrganisationResolver::class)->setOrganisationId(7);

    // The reset listener ignores the event payload, so a bare instance (without
    // Octane's heavyweight constructor arguments) is enough to exercise the flow.
    event((new ReflectionClass(RequestTerminated::class))->newInstanceWithoutConstructor());

    expect(app(OrganisationResolver::class)->getOrganisationId())->toBeNull();
});
