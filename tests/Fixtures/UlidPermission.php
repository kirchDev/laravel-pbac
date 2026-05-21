<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use KirchDev\Pbac\Models\Permission;

final class UlidPermission extends Permission
{
    use HasUlids;
}
