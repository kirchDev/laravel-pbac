<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use KirchDev\Pbac\Models\Permission;

final class UuidPermission extends Permission
{
    use HasUuids;
}
