<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use KirchDev\Pbac\Authorization\PermissionResolver;
use KirchDev\Pbac\Models\Permission;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Tests\Fixtures\User;

it('caches negative lookups so unmanaged abilities do not re-query the database', function () {
    $resolver = app(PermissionResolver::class);
    $resolver->reset();

    DB::enableQueryLog();

    expect($resolver->resolve('totally.unknown'))->toBeNull();
    $firstQueries = DB::getQueryLog();

    DB::flushQueryLog();
    expect($resolver->resolve('totally.unknown'))->toBeNull();
    $secondQueries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($firstQueries)->not->toBe([])
        ->and($secondQueries)->toBe([]);
});

it('caches positive lookups and reuses the model instance', function () {
    Permission::findOrCreate('posts.view');

    $resolver = app(PermissionResolver::class);
    $resolver->reset();

    $first = $resolver->resolve('posts.view');
    $second = $resolver->resolve('posts.view');

    expect($first)->not->toBeNull()
        ->and($second)->toBe($first);
});

it('invalidates the cache when a new permission is created mid-request', function () {
    $resolver = app(PermissionResolver::class);
    $resolver->reset();

    // Negative hit cached.
    expect($resolver->resolve('posts.publish'))->toBeNull();

    // Creating the row must invalidate the cache via the model event hook.
    Permission::findOrCreate('posts.publish');

    expect($resolver->resolve('posts.publish'))->not->toBeNull();
});

it('invalidates the cache when a permission is deleted mid-request', function () {
    $permission = Permission::findOrCreate('posts.delete');

    $resolver = app(PermissionResolver::class);
    $resolver->reset();

    expect($resolver->resolve('posts.delete'))->not->toBeNull();

    $permission->delete();

    expect($resolver->resolve('posts.delete'))->toBeNull();
});

it('avoids the permission lookup query on a second native-gate fallback within the same request', function () {
    $user = User::query()->create(['name' => 'Native', 'email' => 'native@example.com']);

    // Force into the manage_existing_permissions_only=true flow.
    config()->set('pbac.gate.manage_existing_permissions_only', true);

    $user->can('not.managed.anywhere'); // primes negative cache + decision cache

    DB::enableQueryLog();
    $user->can('not.managed.anywhere');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('still recognises an existing permission after the cache was reset', function () {
    Permission::findOrCreate('posts.archive');

    $resolver = app(PermissionResolver::class);
    expect($resolver->resolve('posts.archive'))->not->toBeNull();

    $resolver->reset();

    expect($resolver->resolve('posts.archive'))->not->toBeNull();
});

it('does not collide with the role permission attachment flow', function () {
    $user = User::query()->create(['name' => 'Flow', 'email' => 'flow@example.com']);
    $role = Role::findOrCreate('flow-role');
    $user->assignRole($role, global: true);

    expect($user->can('flow.do'))->toBeFalse();

    $role->givePermissionTo('flow.do');

    expect($user->can('flow.do'))->toBeTrue();
});
