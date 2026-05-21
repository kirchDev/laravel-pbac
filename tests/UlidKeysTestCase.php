<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests;

use KirchDev\Pbac\Tests\Fixtures\UlidPermission;
use KirchDev\Pbac\Tests\Fixtures\UlidRole;

abstract class UlidKeysTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('pbac.keys.primary_key_type', 'ulid');
        $app['config']->set('pbac.models.role', UlidRole::class);
        $app['config']->set('pbac.models.permission', UlidPermission::class);
    }
}
