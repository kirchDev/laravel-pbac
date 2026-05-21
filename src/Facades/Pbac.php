<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Facades;

use Illuminate\Support\Facades\Facade;
use KirchDev\Pbac\PbacManager;

/**
 * @method static int|string|null currentOrganisationId()
 * @method static mixed withOrganisation(int|string|null $organisationId, \Closure $callback)
 * @method static mixed withoutOrganisation(\Closure $callback)
 * @method static void reset()
 *
 * @see PbacManager
 */
final class Pbac extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'pbac';
    }
}
