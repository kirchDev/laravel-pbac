<?php

declare(strict_types=1);

namespace KirchDev\Pbac;

use Closure;
use KirchDev\Pbac\Authorization\DecisionCache;
use KirchDev\Pbac\Authorization\PermissionResolver;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Decision\Decision;
use KirchDev\Pbac\Decision\DecisionTrace;

final class PbacManager
{
    private ?Decision $lastDecision = null;

    private bool $traceUnredactedScope = false;

    public function __construct(
        private readonly OrganisationResolver $organisationResolver,
        private readonly DecisionCache $decisionCache,
        private readonly PermissionResolver $permissionResolver,
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

    /**
     * Run $callback with trace redaction disabled for the duration.
     *
     * Useful for explicit debug routes or admin endpoints that need to surface
     * the full {@see DecisionTrace} contents. The flag
     * is request-scoped (PbacManager is bound as `scoped`) and restored even
     * if $callback throws.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withUnredactedTrace(Closure $callback): mixed
    {
        $previous = $this->traceUnredactedScope;
        $this->traceUnredactedScope = true;

        try {
            return $callback();
        } finally {
            $this->traceUnredactedScope = $previous;
        }
    }

    /**
     * Whether decision traces should currently be redacted.
     *
     * Resolution order:
     *   1. Active {@see self::withUnredactedTrace()} scope wins → false.
     *   2. config('pbac.trace.redact') if boolean → forced.
     *   3. Auto: APP_ENV=production AND APP_DEBUG=false.
     */
    public function isTraceRedacted(): bool
    {
        if ($this->traceUnredactedScope) {
            return false;
        }

        $override = config('pbac.trace.redact');

        if (is_bool($override)) {
            return $override;
        }

        $env = (string) config('app.env', 'production');
        $debug = (bool) config('app.debug', false);

        return $env === 'production' && $debug === false;
    }

    public function rememberDecision(Decision $decision): void
    {
        $this->lastDecision = $decision;
    }

    public function lastDecision(): ?Decision
    {
        return $this->lastDecision;
    }

    public function reset(): void
    {
        $this->organisationResolver->clearOrganisationId();
        $this->decisionCache->reset();
        $this->permissionResolver->reset();
        $this->lastDecision = null;
        $this->traceUnredactedScope = false;
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
