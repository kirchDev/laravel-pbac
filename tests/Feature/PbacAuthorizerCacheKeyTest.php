<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use KirchDev\Pbac\Authorization\PbacAuthorizer;
use KirchDev\Pbac\Models\Permission;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Tests\Fixtures\User;

it('does not collide when an ability name contains the pipe separator', function () {
    $user = User::query()->create(['name' => 'Pipe', 'email' => 'pipe@example.com']);
    Permission::findOrCreate('a|b');
    Permission::findOrCreate('a');

    $role = Role::findOrCreate('viewer');
    $role->givePermissionTo('a');
    $user->assignRole($role, global: true);

    // Old key joined with `|` would have collided 'actor|a|b|...' vs 'actor|a|b'.
    // The hashed key keeps them distinct: user can do 'a' but not 'a|b'.
    expect($user->can('a'))->toBeTrue()
        ->and($user->can('a|b'))->toBeFalse();
});

it('reuses a cached decision instead of re-running the underlying query', function () {
    $user = User::query()->create(['name' => 'Cache', 'email' => 'cache@example.com']);
    $role = Role::findOrCreate('viewer');
    $role->givePermissionTo('posts.view');
    $user->assignRole($role, global: true);

    $authorizer = app(PbacAuthorizer::class);

    // Warm: first call hits the database for permission lookup + role/permission query.
    $authorizer->inspect($user, 'posts.view');

    DB::enableQueryLog();
    $second = $authorizer->inspect($user, 'posts.view');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($second->allowed())->toBeTrue()
        ->and($queries)->toBe([]);
});
