<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Tests\Fixtures\Project;
use KirchDev\Pbac\Tests\Fixtures\User;

it('does not ship direct model permission assignments', function () {
    expect(Schema::hasTable('model_has_permissions'))->toBeFalse();
});

it('authorizes global and organisation scoped roles through Laravel can', function () {
    $resolver = app(OrganisationResolver::class);
    $user = User::query()->create(['name' => 'User A', 'email' => 'a@example.com']);

    $superadmin = Role::findOrCreate('superadmin');
    $superadmin->givePermissionTo('support_panel.access');

    $ownerA = Role::findOrCreate('owner', organisationId: 1);
    $ownerA->givePermissionTo('organisation.members.invite');

    $user->assignRole($superadmin);
    $user->assignRole($ownerA);

    $resolver->setOrganisationId(1);

    expect($user->can('support_panel.access'))->toBeTrue()
        ->and($user->can('organisation.members.invite'))->toBeTrue()
        ->and($user->permissionNames())->toBe([
            'organisation.members.invite',
            'support_panel.access',
        ]);

    $resolver->setOrganisationId(2);

    expect($user->can('support_panel.access'))->toBeTrue()
        ->and($user->can('organisation.members.invite'))->toBeFalse()
        ->and($user->permissionNames())->toBe([
            'support_panel.access',
        ]);

    $resolver->clearOrganisationId();

    expect($user->can('support_panel.access'))->toBeTrue()
        ->and($user->can('organisation.members.invite'))->toBeFalse()
        ->and($user->permissionNames())->toBe([
            'support_panel.access',
        ]);
});

it('matches target specific role permission grants by model morph type and primary key', function () {
    $resolver = app(OrganisationResolver::class);
    $user = User::query()->create(['name' => 'User B', 'email' => 'b@example.com']);
    $projectE = Project::query()->create(['name' => 'Project E']);
    $projectR = Project::query()->create(['name' => 'Project R']);
    $projectA = Project::query()->create(['name' => 'Project A']);

    $member = Role::findOrCreate('member', organisationId: 1);
    $editor = Role::findOrCreate('editor_projects_e_and_r', organisationId: 1);
    $editor->givePermissionTo('projects.view', $projectE);
    $editor->givePermissionTo('projects.view', $projectR);

    $user->assignRole($member);
    $user->assignRole($editor);

    $resolver->setOrganisationId(1);

    expect($user->can('projects.view', $projectE))->toBeTrue()
        ->and($user->can('projects.view', $projectR))->toBeTrue()
        ->and($user->can('projects.view', $projectA))->toBeFalse()
        ->and($user->can('projects.view'))->toBeFalse()
        ->and($user->permissionNames())->toBe([]);
});

it('lets targetless grants apply broadly inside the active role context', function () {
    $resolver = app(OrganisationResolver::class);
    $user = User::query()->create(['name' => 'User C', 'email' => 'c@example.com']);
    $projectE = Project::query()->create(['name' => 'Project E']);
    $projectR = Project::query()->create(['name' => 'Project R']);

    $owner = Role::findOrCreate('owner', organisationId: 1);
    $owner->givePermissionTo('projects.view');

    $user->assignRole($owner);
    $resolver->setOrganisationId(1);

    expect($user->can('projects.view', $projectE))->toBeTrue()
        ->and($user->can('projects.view', $projectR))->toBeTrue()
        ->and($user->permissionNames())->toBe([
            'projects.view',
        ]);
});

it('falls back to normal Laravel gates for unmanaged abilities', function () {
    $user = User::query()->create(['name' => 'User D', 'email' => 'd@example.com']);

    Gate::define('unmanaged.custom', fn (User $user): bool => true);

    expect($user->can('unmanaged.custom'))->toBeTrue();
});

it('resets the request decision cache after role permission mutations', function () {
    $resolver = app(OrganisationResolver::class);
    $user = User::query()->create(['name' => 'User F', 'email' => 'f@example.com']);
    $project = Project::query()->create(['name' => 'Project F']);
    $owner = Role::findOrCreate('owner', organisationId: 1);

    $user->assignRole($owner);
    $resolver->setOrganisationId(1);

    expect($user->can('projects.view', $project))->toBeFalse();

    $owner->givePermissionTo('projects.view');

    expect($user->can('projects.view', $project))->toBeTrue();
});
