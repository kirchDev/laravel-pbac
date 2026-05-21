<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests;

use KirchDev\Pbac\Tests\Fixtures\UuidPermission;
use KirchDev\Pbac\Tests\Fixtures\UuidRole;

abstract class UuidKeysTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pbac.keys.primary_key_type', 'uuid');
        $app['config']->set('pbac.models.role', UuidRole::class);
        $app['config']->set('pbac.models.permission', UuidPermission::class);
    }
}
