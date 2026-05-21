<?php

declare(strict_types=1);

namespace KirchDev\Pbac;

use Closure;
use KirchDev\Pbac\Authorization\DecisionCache;
use KirchDev\Pbac\Contracts\OrganisationResolver;

final readonly class PbacManager
{
    public function __construct(
        private OrganisationResolver $organisationResolver,
        private DecisionCache $decisionCache,
    ) {}

    public function currentOrganisationId(): int|string|null
    {
        return $this->organisationResolver->getOrganisationId();
    }

    /**
     * Run $callback with the given organisation as the active context.
     *
     * The previous context is captured before the call and restored after,
     * even when $callback throws. Decision cache is reset on enter and exit
     * so role/permission lookups never bleed across scopes.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withOrganisation(int|string|null $organisationId, Closure $callback): mixed
    {
        return $this->runInScope($organisationId, $callback);
    }

    /**
     * Run $callback with no active organisation context.
     *
     * Mirrors {@see self::withOrganisation()}; convenience for global checks.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutOrganisation(Closure $callback): mixed
    {
        return $this->runInScope(null, $callback);
    }

    public function reset(): void
    {
        $this->organisationResolver->clearOrganisationId();
        $this->decisionCache->reset();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function runInScope(int|string|null $organisationId, Closure $callback): mixed
    {
        $previousOrganisationId = $this->organisationResolver->getOrganisationId();

        $this->applyScope($organisationId);

        try {
            return $callback();
        } finally {
            $this->applyScope($previousOrganisationId);
        }
    }

    private function applyScope(int|string|null $organisationId): void
    {
        if ($organisationId === null) {
            $this->organisationResolver->clearOrganisationId();
        } else {
            $this->organisationResolver->setOrganisationId($organisationId);
        }

        $this->decisionCache->reset();
    }
}
