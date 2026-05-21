<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use KirchDev\Pbac\Authorization\PbacAuthorizer;
use KirchDev\Pbac\Contracts\Authorizer;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Queries\RolePermissionQuery;
use KirchDev\Pbac\Tests\Fixtures\User;

it('returns pbac.actor_not_model when the actor passed in is not a Model', function () {
    /** @var PbacAuthorizer $authorizer */
    $authorizer = app(Authorizer::class);

    $decision = $authorizer->inspect('not-a-model', 'posts.update');

    expect($decision)->not->toBeNull()
        ->and($decision->denied())->toBeTrue()
        ->and($decision->reason())->toBe('pbac.actor_not_model');
});

it('hashes a stable cache key even when the actor is not a Model', function () {
    /** @var PbacAuthorizer $authorizer */
    $authorizer = app(Authorizer::class);

    // Two consecutive identical calls; both should hit the cache after the first.
    $authorizer->inspect('not-a-model', 'whatever');
    $second = $authorizer->inspect('not-a-model', 'whatever');

    expect($second->reason())->toBe('pbac.actor_not_model');
});

it('throws with the global-scope message when no organisation is enabled', function () {
    config()->set('pbac.organisation.enabled', false);

    $user = User::query()->create(['name' => 'GlobalOnly', 'email' => 'global-only@example.com']);

    expect(fn () => $user->assignRole('phantom'))
        ->toThrow(InvalidArgumentException::class, 'Role [phantom] not found.');
});

it('runs RolePermissionQuery without the organisation predicate when the feature is off', function () {
    config()->set('pbac.organisation.enabled', false);

    $user = User::query()->create(['name' => 'NoOrg', 'email' => 'noorg@example.com']);
    $role = Role::findOrCreate('reader');
    $role->givePermissionTo('posts.read');

    /** @var Model $user */
    $user->assignRole($role);

    /** @var RolePermissionQuery $query */
    $query = app(RolePermissionQuery::class);

    expect($query->untargetedPermissionNamesFor($user))->toBe(['posts.read']);
});
