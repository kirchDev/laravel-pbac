<?php

declare(strict_types=1);

use KirchDev\Pbac\PbacManager;
use KirchDev\Pbac\Traits\InteractsWithOrganisationContext;

it('runs the callback inside the host organisation context', function () {
    $host = new class
    {
        use InteractsWithOrganisationContext;

        public ?string $organisation = 'org-from-host';

        public function run(callable $callback): mixed
        {
            return $this->runWithOrganisationContext($callback);
        }

        protected function organisationContextId(): int|string|null
        {
            return $this->organisation;
        }
    };

    $pbac = app(PbacManager::class);
    $observed = null;

    $host->run(function () use ($pbac, &$observed): void {
        $observed = $pbac->currentOrganisationId();
    });

    expect($observed)->toBe('org-from-host')
        ->and($pbac->currentOrganisationId())->toBeNull();
});

it('passes through a null organisation id without leaking outer scope', function () {
    $host = new class
    {
        use InteractsWithOrganisationContext;

        public function run(callable $callback): mixed
        {
            return $this->runWithOrganisationContext($callback);
        }

        protected function organisationContextId(): int|string|null
        {
            return null;
        }
    };

    $pbac = app(PbacManager::class);
    $pbac->withOrganisation('outer', function () use ($host, $pbac): void {
        $observed = 'unset';

        $host->run(function () use ($pbac, &$observed): void {
            $observed = $pbac->currentOrganisationId();
        });

        expect($observed)->toBeNull()
            ->and($pbac->currentOrganisationId())->toBe('outer');
    });
});
