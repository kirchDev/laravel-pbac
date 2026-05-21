<?php

declare(strict_types=1);

use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Tests\Fixtures\UlidRole;
use KirchDev\Pbac\Tests\Fixtures\User;

it('creates ULID-keyed roles and permissions through the migration schema', function () {
    $role = UlidRole::query()->create(['name' => 'ulid-editor']);

    expect($role->getKey())->toBeString()
        ->and(strlen((string) $role->getKey()))->toBe(26)
        ->and($role->getKeyType())->toBe('string');
});

it('runs an end-to-end Gate::allows flow with ULID-keyed role and permission tables', function () {
    $user = User::query()->create(['name' => 'Ulid', 'email' => 'ulid@example.com']);
    $role = UlidRole::query()->create(['name' => 'ulid-editor']);
    $role->givePermissionTo('ulid.posts.update');

    $user->assignRole($role);

    app(OrganisationResolver::class)->clearOrganisationId();

    expect($user->can('ulid.posts.update'))->toBeTrue()
        ->and($user->can('ulid.posts.delete'))->toBeFalse();
});
