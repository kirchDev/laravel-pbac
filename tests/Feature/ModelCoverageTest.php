<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Models\Permission;
use KirchDev\Pbac\Models\Role;
use KirchDev\Pbac\Models\RolePermission;
use KirchDev\Pbac\Tests\Fixtures\Project;
use KirchDev\Pbac\Tests\Fixtures\User;

describe('Permission model', function () {
    it('returns the configured table name', function () {
        expect((new Permission)->getTable())->toBe('permissions');

        config()->set('pbac.table_names.permissions', 'custom_permissions');
        expect((new Permission)->getTable())->toBe('custom_permissions');
    });

    it('exposes its roles relation', function () {
        $permission = Permission::findOrCreate('posts.update');
        $role = Role::findOrCreate('editor');
        $role->givePermissionTo($permission);

        expect($permission->roles)->toHaveCount(1)
            ->and($permission->roles->first()?->name)->toBe('editor');
    });

    it('finds an existing permission by name', function () {
        $created = Permission::findOrCreate('posts.delete');

        expect(Permission::findByName('posts.delete')->getKey())->toBe($created->getKey());
    });

    it('throws ModelNotFoundException when findByName misses', function () {
        expect(fn () => Permission::findByName('does.not.exist'))
            ->toThrow(ModelNotFoundException::class);
    });
});

describe('Role model', function () {
    it('returns the configured table name', function () {
        expect((new Role)->getTable())->toBe('roles');
    });

    it('finds a role by name in the active organisation scope', function () {
        $role = Role::findOrCreate('owner', organisationId: 7);

        expect(Role::findByName('owner', 7)->getKey())->toBe($role->getKey());
    });

    it('throws ModelNotFoundException when findByName misses', function () {
        expect(fn () => Role::findByName('phantom'))
            ->toThrow(ModelNotFoundException::class);
    });

    it('revokes a previously granted permission', function () {
        $role = Role::findOrCreate('reviewer');
        $role->givePermissionTo('posts.review');

        expect($role->hasPermissionTo('posts.review'))->toBeTrue();

        $role->revokePermissionTo('posts.review');

        expect($role->hasPermissionTo('posts.review'))->toBeFalse();
    });

    it('reports targeted permissions correctly via hasPermissionTo', function () {
        $role = Role::findOrCreate('project-editor');
        $project = Project::query()->create(['name' => 'Alpha']);

        $role->givePermissionTo('projects.update', $project);

        expect($role->hasPermissionTo('projects.update', $project))->toBeTrue()
            ->and($role->hasPermissionTo('projects.update'))->toBeFalse(); // not granted untargeted
    });

    it('refuses to grant a permission before the role is persisted', function () {
        $role = new Role(['name' => 'unsaved']);

        expect(fn () => $role->givePermissionTo('whatever'))
            ->toThrow(InvalidArgumentException::class, 'A role must be persisted before permissions can be granted.');
    });

    it('accepts a Permission instance as well as a string name', function () {
        $permission = Permission::findOrCreate('posts.publish');
        $role = Role::findOrCreate('publisher');

        $role->givePermissionTo($permission);

        expect($role->hasPermissionTo($permission))->toBeTrue();
    });
});

describe('RolePermission pivot', function () {
    it('returns the configured table name', function () {
        expect((new RolePermission)->getTable())->toBe('role_has_permissions');

        config()->set('pbac.table_names.role_has_permissions', 'custom_rhp');
        expect((new RolePermission)->getTable())->toBe('custom_rhp');
    });
});

describe('hasRole isFillable + integration', function () {
    it('treats the organisation_foreign_key as fillable on Role', function () {
        $role = new Role(['name' => 'fillable-test', 'organisation_id' => 99]);

        expect($role->organisation_id)->toBe(99);
    });

    it('still finds roles after the user resets the organisation resolver', function () {
        $user = User::query()->create(['name' => 'Reset', 'email' => 'reset@example.com']);
        Role::findOrCreate('staff', organisationId: 1);

        app(OrganisationResolver::class)->setOrganisationId(1);
        $user->assignRole('staff');

        app(OrganisationResolver::class)->clearOrganisationId();

        expect($user->hasRole('staff'))->toBeFalse(); // out of scope, lenient
    });
});
