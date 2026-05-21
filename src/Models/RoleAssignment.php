<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

class RoleAssignment extends MorphPivot
{
    public function getTable(): string
    {
        return config('pbac.table_names.model_has_roles', 'model_has_roles');
    }
}
