<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Gate;

use Illuminate\Auth\Access\Response;
use KirchDev\Pbac\Contracts\Authorizer;

final readonly class PbacGate
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     */
    public function before(mixed $user, string $ability, array $arguments): ?Response
    {
        $decision = $this->authorizer->inspect($user, $ability, $arguments);

        if ($decision === null) {
            return (bool) config('pbac.gate.fallback_to_laravel_gates', true)
                ? null
                : Response::deny('pbac.permission_not_found');
        }

        return $decision->allowed()
            ? Response::allow()
            : Response::deny($decision->reason());
    }
}
