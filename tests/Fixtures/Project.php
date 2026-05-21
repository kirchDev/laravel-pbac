<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Project extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
    ];
}
