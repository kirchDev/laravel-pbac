<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Tests\Fixtures;

use Closure;
use KirchDev\Pbac\Traits\InteractsWithOrganisationContext;

/**
 * Fixture demonstrating consumer integration of
 * {@see InteractsWithOrganisationContext} for a job-like host class.
 */
final class OrganisationAwareHost
{
    use InteractsWithOrganisationContext;

    public function __construct(
        private readonly int|string|null $organisationId = null,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        return $this->runWithOrganisationContext($callback);
    }

    protected function organisationContextId(): int|string|null
    {
        return $this->organisationId;
    }
}
