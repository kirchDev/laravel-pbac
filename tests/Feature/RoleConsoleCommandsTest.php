<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use KirchDev\Pbac\Facades\Pbac;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Tests\Fixtures\Project;
use KirchDev\Pbac\Tests\Fixtures\User;

function pbacConsoleUser(string $email = 'first@example.test'): User
{
    return User::query()->create([
        'name' => 'First Admin',
        'email' => $email,
    ]);
}

it('assigns a global role to a target resolved by primary key', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ])->assertSuccessful();

    expect($user->fresh()->hasRole('admin', global: true))->toBeTrue();
});

it('revokes a global role from a target', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('admin');
    $user->assignRole('admin', global: true);

    $this->artisan('pbac:role:revoke', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ])->assertSuccessful();

    expect($user->fresh()->hasRole('admin', global: true))->toBeFalse();
});

it('assigns an organisation scoped role within the given organisation', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('manager', 7);
    Role::findOrCreate('manager', 9);

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'manager',
        '--organisation' => '7',
    ])->assertSuccessful();

    $fresh = $user->fresh();

    expect(Pbac::withOrganisation(7, fn () => $fresh->hasRole('manager')))->toBeTrue()
        ->and(Pbac::withOrganisation(9, fn () => $fresh->hasRole('manager')))->toBeFalse();
});

it('refuses to act when neither scope flag is given and organisations are enabled', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
    ])->assertFailed();

    expect($user->fresh()->roles()->count())->toBe(0);
});

it('refuses to act when both scope flags are given', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
        '--organisation' => '7',
    ])->assertFailed();

    expect($user->fresh()->roles()->count())->toBe(0);
});

it('refuses a scope flag while the organisation feature is disabled', function () {
    config()->set('pbac.organisation.enabled', false);

    $user = pbacConsoleUser();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ])->assertFailed();

    expect($user->fresh()->roles()->count())->toBe(0);
});

it('resolves a role by name alone while the organisation feature is disabled', function () {
    config()->set('pbac.organisation.enabled', false);

    $user = pbacConsoleUser();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
    ])->assertSuccessful();

    expect($user->fresh()->hasRole('admin'))->toBeTrue();
});

it('looks the target up by an explicit column', function () {
    $user = pbacConsoleUser('ops@example.test');
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => 'ops@example.test',
        'role' => 'admin',
        '--global' => true,
        '--column' => 'email',
    ])->assertSuccessful();

    expect($user->fresh()->hasRole('admin', global: true))->toBeTrue();
});

it('prefers the configured default model over the auth provider model', function () {
    config()->set('auth.providers.users.model', Project::class);
    config()->set('pbac.models.default_model', User::class);

    $user = pbacConsoleUser();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ])->assertSuccessful();

    expect($user->fresh()->hasRole('admin', global: true))->toBeTrue();
});

it('lets --model override both the configured default and the auth provider model', function () {
    config()->set('auth.providers.users.model', Project::class);
    config()->set('pbac.models.default_model', Project::class);

    $user = pbacConsoleUser();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
        '--model' => User::class,
    ])->assertSuccessful();

    expect($user->fresh()->hasRole('admin', global: true))->toBeTrue();
});

it('fails and asks for --model when no target model can be determined', function () {
    config()->set('pbac.models.default_model', null);
    config()->set('auth.providers.users.model', null);

    $user = pbacConsoleUser();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ])->expectsOutputToContain('--model')->assertFailed();

    expect($user->fresh()->roles()->count())->toBe(0);
});

it('rejects a target model that does not use the HasRoles trait', function () {
    Project::query()->create(['name' => 'Apollo']);
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => '1',
        'role' => 'admin',
        '--global' => true,
        '--model' => Project::class,
    ])->expectsOutputToContain('HasRoles')->assertFailed();
});

it('rejects a --model that is not an Eloquent model class', function () {
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => '1',
        'role' => 'admin',
        '--global' => true,
        '--model' => 'App\\Models\\DoesNotExist',
    ])->assertFailed();
});

it('fails with a clear message when the target cannot be found', function () {
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => '404',
        'role' => 'admin',
        '--global' => true,
    ])->expectsOutputToContain('404')->assertFailed();
});

it('refuses an ambiguous identifier lookup rather than picking the first match', function () {
    pbacConsoleUser('one@example.test')->forceFill(['name' => 'Shared'])->save();
    pbacConsoleUser('two@example.test')->forceFill(['name' => 'Shared'])->save();
    Role::findOrCreate('admin');

    $this->artisan('pbac:role:assign', [
        'identifier' => 'Shared',
        'role' => 'admin',
        '--global' => true,
        '--column' => 'name',
    ])->expectsOutputToContain('ambiguous')->assertFailed();

    expect(User::query()->where('name', 'Shared')->get())
        ->each(fn ($user) => expect($user->value->roles()->count())->toBe(0));
});

it('fails cleanly when the role does not exist in the requested scope', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('admin', 7);

    $this->artisan('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ])->expectsOutputToContain('admin')->assertFailed();

    expect($user->fresh()->roles()->count())->toBe(0);
});

it('prints the resulting role set with each role scope after assigning', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('admin');
    Role::findOrCreate('manager', 7);
    Pbac::withOrganisation(7, fn () => $user->assignRole('manager'));

    $exitCode = Artisan::call('pbac:role:assign', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())
        ->toMatch('/\|\s*admin\s*\|\s*global\s*\|/')
        ->toMatch('/\|\s*manager\s*\|\s*7\s*\|/');
});

it('prints the resulting role set after revoking', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('admin');
    Role::findOrCreate('auditor');
    $user->assignRoles('admin', 'auditor', global: true);

    $exitCode = Artisan::call('pbac:role:revoke', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())
        ->toMatch('/\|\s*auditor\s*\|\s*global\s*\|/')
        ->not->toMatch('/\|\s*admin\s*\|/');
});

it('reports an empty role set once the last role is revoked', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('admin');
    $user->assignRole('admin', global: true);

    $exitCode = Artisan::call('pbac:role:revoke', [
        'identifier' => (string) $user->getKey(),
        'role' => 'admin',
        '--global' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('No roles assigned.');
});

it('revokes an organisation scoped role without touching the global one', function () {
    $user = pbacConsoleUser();
    Role::findOrCreate('manager');
    Role::findOrCreate('manager', 7);
    $user->assignRole('manager', global: true);
    Pbac::withOrganisation(7, fn () => $user->assignRole('manager'));

    $this->artisan('pbac:role:revoke', [
        'identifier' => (string) $user->getKey(),
        'role' => 'manager',
        '--organisation' => '7',
    ])->assertSuccessful();

    $fresh = $user->fresh();

    expect(Pbac::withOrganisation(7, fn () => $fresh->hasRole('manager')))->toBeFalse()
        ->and($fresh->hasRole('manager', global: true))->toBeTrue();
});

it('registers both role commands unconditionally', function () {
    $commands = app(Kernel::class)->all();

    expect($commands)->toHaveKey('pbac:role:assign')
        ->and($commands)->toHaveKey('pbac:role:revoke')
        ->and(config('pbac.commands'))->not->toHaveKey('role_assign')
        ->and(config('pbac.commands'))->not->toHaveKey('role_revoke');
});
