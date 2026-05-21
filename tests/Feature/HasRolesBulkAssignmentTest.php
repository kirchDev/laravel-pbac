<?php

declare(strict_types=1);

use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Tests\Fixtures\User;

it('assigns multiple roles in a single call', function () {
    $user = User::query()->create(['name' => 'Bulk A', 'email' => 'bulk-a@example.com']);

    $editor = Role::findOrCreate('editor');
    $reviewer = Role::findOrCreate('reviewer');

    $user->assignRoles($editor, 'reviewer');

    expect($user->hasRole('editor'))->toBeTrue()
        ->and($user->hasRole($reviewer))->toBeTrue();
});

it('is a no-op when assignRoles is called with no arguments', function () {
    $user = User::query()->create(['name' => 'Bulk B', 'email' => 'bulk-b@example.com']);

    Role::findOrCreate('editor');

    $user->assignRoles();

    expect($user->roles()->count())->toBe(0);
});

it('deduplicates role keys when bulk assigning the same role multiple times', function () {
    $user = User::query()->create(['name' => 'Bulk C', 'email' => 'bulk-c@example.com']);

    $editor = Role::findOrCreate('editor');

    $user->assignRoles($editor, 'editor', $editor->getKey());

    expect($user->roles()->count())->toBe(1);
});

it('removes multiple roles in a single call', function () {
    $user = User::query()->create(['name' => 'Bulk D', 'email' => 'bulk-d@example.com']);

    $editor = Role::findOrCreate('editor');
    $reviewer = Role::findOrCreate('reviewer');
    $auditor = Role::findOrCreate('auditor');

    $user->assignRoles($editor, $reviewer, $auditor);
    $user->removeRoles('editor', $reviewer);

    expect($user->hasRole('editor'))->toBeFalse()
        ->and($user->hasRole($reviewer))->toBeFalse()
        ->and($user->hasRole($auditor))->toBeTrue();
});

it('syncs the active role set, detaching anything not in the input', function () {
    $user = User::query()->create(['name' => 'Bulk E', 'email' => 'bulk-e@example.com']);

    $editor = Role::findOrCreate('editor');
    $reviewer = Role::findOrCreate('reviewer');
    $auditor = Role::findOrCreate('auditor');

    $user->assignRoles($editor, $reviewer);
    $user->syncRoles([$reviewer, $auditor]);

    expect($user->hasRole($editor))->toBeFalse()
        ->and($user->hasRole($reviewer))->toBeTrue()
        ->and($user->hasRole($auditor))->toBeTrue();
});

it('detaches every role when syncRoles receives an empty iterable', function () {
    $user = User::query()->create(['name' => 'Bulk F', 'email' => 'bulk-f@example.com']);

    $editor = Role::findOrCreate('editor');
    $reviewer = Role::findOrCreate('reviewer');

    $user->assignRoles($editor, $reviewer);
    $user->syncRoles([]);

    expect($user->roles()->count())->toBe(0);
});

it('resolves bulk role names against the active organisation scope', function () {
    $resolver = app(OrganisationResolver::class);
    $user = User::query()->create(['name' => 'Bulk G', 'email' => 'bulk-g@example.com']);

    $ownerOrgOne = Role::findOrCreate('owner', organisationId: 1);
    Role::findOrCreate('owner', organisationId: 2);

    $resolver->setOrganisationId(1);

    $user->assignRoles('owner');

    expect($user->roles()->whereKey($ownerOrgOne->getKey())->exists())->toBeTrue()
        ->and($user->roles()->count())->toBe(1);
});

it('accepts a generator as syncRoles input', function () {
    $user = User::query()->create(['name' => 'Bulk H', 'email' => 'bulk-h@example.com']);

    Role::findOrCreate('editor');
    Role::findOrCreate('reviewer');

    $generator = (function () {
        yield 'editor';
        yield 'reviewer';
    })();

    $user->syncRoles($generator);

    expect($user->hasRole('editor'))->toBeTrue()
        ->and($user->hasRole('reviewer'))->toBeTrue();
});
