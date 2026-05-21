<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Tests\Fixtures\User;

it('requires the global flag to target a global role when org scoping is enabled', function () {
    $user = User::query()->create(['name' => 'A', 'email' => 'a@example.com']);
    Role::findOrCreate('superadmin'); // global

    app(OrganisationResolver::class)->clearOrganisationId();

    expect(fn () => $user->assignRole('superadmin'))
        ->toThrow(InvalidArgumentException::class, 'Refusing to resolve role [superadmin]: no active organisation.');
});

it('assigns the global role when global: true is passed', function () {
    $user = User::query()->create(['name' => 'B', 'email' => 'b@example.com']);
    Role::findOrCreate('superadmin');

    app(OrganisationResolver::class)->clearOrganisationId();

    $user->assignRole('superadmin', global: true);

    expect($user->roles()->pluck('name')->all())->toBe(['superadmin']);
});

it('allows global: true even while another organisation context is active', function () {
    $user = User::query()->create(['name' => 'C', 'email' => 'c@example.com']);
    Role::findOrCreate('superadmin');

    app(OrganisationResolver::class)->setOrganisationId(7);

    $user->assignRole('superadmin', global: true);

    expect($user->roles()->pluck('name')->all())->toBe(['superadmin']);
});

it('refuses to silently fall back to a global role when an organisation scope is active', function () {
    $user = User::query()->create(['name' => 'D', 'email' => 'd@example.com']);
    Role::findOrCreate('superadmin'); // global only

    app(OrganisationResolver::class)->setOrganisationId(7);

    expect(fn () => $user->assignRole('superadmin'))
        ->toThrow(InvalidArgumentException::class, 'Role [superadmin] not found (organisation: 7).');
});

it('resolves the organisation-scoped row when a global row with the same name also exists', function () {
    $user = User::query()->create(['name' => 'E', 'email' => 'e@example.com']);
    $global = Role::findOrCreate('editor'); // global
    $orgRole = Role::findOrCreate('editor', organisationId: 1);

    app(OrganisationResolver::class)->setOrganisationId(1);
    $user->assignRole('editor');

    expect($user->roles()->pluck('id')->all())->toBe([$orgRole->getKey()])
        ->and($user->roles()->pluck('id')->all())->not->toContain($global->getKey());
});

it('does not bleed a role from another organisation', function () {
    $user = User::query()->create(['name' => 'F', 'email' => 'f@example.com']);
    Role::findOrCreate('owner', organisationId: 1);

    app(OrganisationResolver::class)->setOrganisationId(2);

    expect(fn () => $user->assignRole('owner'))
        ->toThrow(InvalidArgumentException::class, 'Role [owner] not found (organisation: 2).');
});

it('keeps the ModelNotFoundException when looking up by integer key', function () {
    $user = User::query()->create(['name' => 'G', 'email' => 'g@example.com']);

    expect(fn () => $user->assignRole(9_999_999))
        ->toThrow(ModelNotFoundException::class);
});

it('returns false from hasRole when the requested role is not resolvable instead of throwing', function () {
    $user = User::query()->create(['name' => 'H', 'email' => 'h@example.com']);

    app(OrganisationResolver::class)->clearOrganisationId();

    expect($user->hasRole('ghost'))->toBeFalse()
        ->and($user->hasRole('superadmin', global: true))->toBeFalse();
});
