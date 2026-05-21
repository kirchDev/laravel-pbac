<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class RolePermission extends Pivot
{
    public function getTable(): string
    {
        return config('pbac.table_names.role_has_permissions', 'role_has_permissions');
    }
}
