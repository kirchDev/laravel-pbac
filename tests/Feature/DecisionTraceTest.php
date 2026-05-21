<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use KirchDev\Pbac\Decision\Decision;
use KirchDev\Pbac\Decision\DecisionTrace;
use KirchDev\Pbac\Facades\Pbac;
use KirchDev\Pbac\Models\Permission;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\PbacManager;
use KirchDev\Pbac\Tests\Fixtures\User;

it('redacts trace context arrays in production with debug off', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    config()->set('pbac.trace.redact', null);

    $trace = (new DecisionTrace)->add('first', ['ability' => 'posts.update']);

    expect($trace->visible())->toBe([
        ['step' => 'first', 'context' => []],
    ]);
});

it('keeps trace context when APP_DEBUG is on even in production', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', true);
    config()->set('pbac.trace.redact', null);

    $trace = (new DecisionTrace)->add('first', ['ability' => 'posts.update']);

    expect($trace->visible())->toBe([
        ['step' => 'first', 'context' => ['ability' => 'posts.update']],
    ]);
});

it('honours explicit redact override', function () {
    config()->set('app.env', 'local');
    config()->set('app.debug', true);
    config()->set('pbac.trace.redact', true);

    $trace = (new DecisionTrace)->add('first', ['ability' => 'posts.update']);

    expect($trace->visible())->toBe([
        ['step' => 'first', 'context' => []],
    ]);
});

it('exposes unredacted trace inside withUnredactedTrace scope', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    config()->set('pbac.trace.redact', null);

    $trace = (new DecisionTrace)->add('first', ['ability' => 'posts.update']);

    expect($trace->visible())->toBe([['step' => 'first', 'context' => []]]);

    Pbac::withUnredactedTrace(function () use ($trace) {
        expect($trace->visible())->toBe([
            ['step' => 'first', 'context' => ['ability' => 'posts.update']],
        ]);
    });

    expect($trace->visible())->toBe([['step' => 'first', 'context' => []]]);
});

it('restores unredacted scope after callback throws', function () {
    config()->set('pbac.trace.redact', true);

    try {
        Pbac::withUnredactedTrace(function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // ignored
    }

    expect(app(PbacManager::class)->isTraceRedacted())->toBeTrue();
});

it('remembers the most recent decision via PbacManager::lastDecision', function () {
    $user = User::query()->create(['name' => 'Trace User', 'email' => 'trace@example.com']);

    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('posts.update');
    $user->assignRole($role);

    $user->can('posts.update');

    $decision = Pbac::lastDecision();

    expect($decision)->toBeInstanceOf(Decision::class)
        ->and($decision->allowed())->toBeTrue()
        ->and($decision->reason())->toBe('pbac.role_permission_allowed');
});

it('logs denied decisions when trace.log.enabled and on=deny', function () {
    config()->set('pbac.trace.log.enabled', true);
    config()->set('pbac.trace.log.on', 'deny');
    config()->set('pbac.trace.log.level', 'warning');

    $user = User::query()->create(['name' => 'Log User', 'email' => 'log@example.com']);
    Permission::findOrCreate('posts.delete');

    Log::spy();

    $user->can('posts.delete'); // denied — no role grants it

    Log::shouldHaveReceived('log')->atLeast()->once();
});

it('skips logging allowed decisions when on=deny', function () {
    config()->set('pbac.trace.log.enabled', true);
    config()->set('pbac.trace.log.on', 'deny');

    $user = User::query()->create(['name' => 'Allowed User', 'email' => 'allow@example.com']);
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('posts.create');
    $user->assignRole($role);

    Log::spy();

    $user->can('posts.create');

    Log::shouldNotHaveReceived('log');
});
