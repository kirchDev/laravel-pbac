<?php

declare(strict_types=1);

use KirchDev\Pbac\Tests\TestCase;
use KirchDev\Pbac\Tests\UlidKeysTestCase;
use KirchDev\Pbac\Tests\UuidKeysTestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(UuidKeysTestCase::class)->in('UuidKeys');
pest()->extend(UlidKeysTestCase::class)->in('UlidKeys');
