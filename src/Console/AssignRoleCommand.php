<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Console;

use Illuminate\Database\Eloquent\Model;

final class AssignRoleCommand extends RoleCommand
{
    protected $signature = 'pbac:role:assign
        {identifier : Identifier of the target model}
        {role : Name of the role}
        {--global : Target the global role}
        {--organisation= : Target the role bound to this organisation}
        {--model= : Fully qualified target model class (overrides pbac.models.default_model and the auth provider model)}
        {--column= : Column to look the identifier up on (defaults to the primary key)}
    ';

    protected $description = 'Assign a role to a model.';

    protected function apply(Model $target, string $role, bool $global): void
    {
        // resolveTarget() rejects targets without the HasRoles trait; method_exists
        // carries that guarantee into static analysis.
        if (method_exists($target, 'assignRole')) {
            $target->assignRole($role, $global);
        }
    }
}
