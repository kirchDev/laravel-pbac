<?php

declare(strict_types=1);

use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Tests\Fixtures\User;
use KirchDev\Pbac\Tests\Fixtures\UuidRole;

it('creates UUID-keyed roles and permissions through the migration schema', function () {
    $role = UuidRole::query()->create(['name' => 'uuid-editor']);

    expect($role->getKey())->toBeString()
        ->and(strlen((string) $role->getKey()))->toBe(36)
        ->and($role->getKeyType())->toBe('string');
});

it('runs an end-to-end Gate::allows flow with UUID-keyed role and permission tables', function () {
    $user = User::query()->create(['name' => 'Uuid', 'email' => 'uuid@example.com']);
    $role = UuidRole::query()->create(['name' => 'uuid-editor']);
    $role->givePermissionTo('uuid.posts.update');

    $user->assignRole($role);

    app(OrganisationResolver::class)->clearOrganisationId();

    expect($user->can('uuid.posts.update'))->toBeTrue()
        ->and($user->can('uuid.posts.delete'))->toBeFalse();
});
