<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use KirchDev\Pbac\Models\Role;

final class UuidRole extends Role
{
    use HasUuids;
}
