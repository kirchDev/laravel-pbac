<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use KirchDev\Pbac\Facades\Pbac;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Tests\Fixtures\User;

it('denies with pbac.permission_not_found when manage_existing_permissions_only is disabled', function () {
    config()->set('pbac.gate.manage_existing_permissions_only', false);

    $user = User::query()->create(['name' => 'Unknown', 'email' => 'unknown@example.com']);

    Gate::define('completely.unknown', fn () => true);

    expect($user->can('completely.unknown'))->toBeFalse()
        ->and(Pbac::lastDecision()?->reason())->toBe('pbac.permission_not_found');
});

it('falls back to Laravel gates when fallback_to_laravel_gates is true (default) and the ability is unmanaged', function () {
    config()->set('pbac.gate.manage_existing_permissions_only', true);
    config()->set('pbac.gate.fallback_to_laravel_gates', true);

    $user = User::query()->create(['name' => 'Fallback', 'email' => 'fallback@example.com']);

    Gate::define('native.thing', fn () => true);

    expect($user->can('native.thing'))->toBeTrue();
});

it('does not fall back to Laravel gates when fallback_to_laravel_gates is false', function () {
    config()->set('pbac.gate.manage_existing_permissions_only', true);
    config()->set('pbac.gate.fallback_to_laravel_gates', false);

    $user = User::query()->create(['name' => 'NoFallback', 'email' => 'nofallback@example.com']);

    Gate::define('native.thing', fn () => true);

    expect($user->can('native.thing'))->toBeFalse();
});

it('still grants pbac-managed abilities when fallback_to_laravel_gates is false', function () {
    config()->set('pbac.gate.fallback_to_laravel_gates', false);

    $user = User::query()->create(['name' => 'Managed', 'email' => 'managed@example.com']);
    $role = Role::findOrCreate('viewer');
    $role->givePermissionTo('posts.view');
    $user->assignRole($role, global: true);

    expect($user->can('posts.view'))->toBeTrue();
});
