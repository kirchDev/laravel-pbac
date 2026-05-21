<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use KirchDev\Pbac\Traits\HasRoles;

final class User extends Authenticatable
{
    use HasRoles;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
    ];
}
