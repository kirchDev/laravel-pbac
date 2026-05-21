<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use KirchDev\Pbac\Models\Role;

final class UlidRole extends Role
{
    use HasUlids;
}
