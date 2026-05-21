<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Traits;

use Closure;
use KirchDev\Pbac\PbacManager;

/**
 * Helper for queue jobs, broadcast handlers, scheduled commands and other
 * code that runs outside the HTTP middleware stack. The host class declares
 * which organisation id should be active, and {@see self::runWithOrganisationContext()}
 * routes execution through {@see PbacManager::withOrganisation()} so the
 * scope is always cleaned up afterwards.
 */
trait InteractsWithOrganisationContext
{
    /**
     * Return the organisation id this work should run inside, or null for
     * a global (cross-tenant) scope.
     */
    abstract protected function organisationContextId(): int|string|null;

    /**
     * Execute $callback with the host's organisation id as the active scope.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function runWithOrganisationContext(Closure $callback): mixed
    {
        return app(PbacManager::class)->withOrganisation(
            $this->organisationContextId(),
            $callback,
        );
    }
}
