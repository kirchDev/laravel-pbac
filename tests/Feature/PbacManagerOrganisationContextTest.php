<?php

declare(strict_types=1);

use KirchDev\Pbac\Authorization\DecisionCache;
use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Decision\Decision;
use KirchDev\Pbac\PbacManager;

it('exposes the organisation id only inside the scope and restores after', function () {
    $pbac = app(PbacManager::class);
    $observed = null;

    $pbac->withOrganisation('org-1', function () use ($pbac, &$observed): void {
        $observed = $pbac->currentOrganisationId();
    });

    expect($observed)->toBe('org-1')
        ->and($pbac->currentOrganisationId())->toBeNull();
});

it('supports nesting and unwinds to the outer organisation', function () {
    $pbac = app(PbacManager::class);
    $inner = null;
    $afterInner = null;

    $pbac->withOrganisation('outer', function () use ($pbac, &$inner, &$afterInner): void {
        $pbac->withOrganisation('inner', function () use ($pbac, &$inner): void {
            $inner = $pbac->currentOrganisationId();
        });
        $afterInner = $pbac->currentOrganisationId();
    });

    expect($inner)->toBe('inner')
        ->and($afterInner)->toBe('outer')
        ->and($pbac->currentOrganisationId())->toBeNull();
});

it('restores the previous organisation when the callback throws', function () {
    $pbac = app(PbacManager::class);
    app(OrganisationResolver::class)->setOrganisationId('before');

    try {
        $pbac->withOrganisation('inside', function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($pbac->currentOrganisationId())->toBe('before');
});

it('resets the decision cache when entering and exiting the scope', function () {
    $pbac = app(PbacManager::class);
    $cache = app(DecisionCache::class);

    $cache->put('before-enter', Decision::allow('seeded'));
    $insideHadEntry = null;

    $pbac->withOrganisation('org-1', function () use ($cache, &$insideHadEntry): void {
        $insideHadEntry = $cache->has('before-enter');
        $cache->put('inside-scope', Decision::allow('mid'));
    });

    expect($insideHadEntry)->toBeFalse()
        ->and($cache->has('inside-scope'))->toBeFalse();
});

it('clears organisation context for the duration of withoutOrganisation', function () {
    $pbac = app(PbacManager::class);
    app(OrganisationResolver::class)->setOrganisationId('outer');
    $observed = 'unset';

    $pbac->withoutOrganisation(function () use ($pbac, &$observed): void {
        $observed = $pbac->currentOrganisationId();
    });

    expect($observed)->toBeNull()
        ->and($pbac->currentOrganisationId())->toBe('outer');
});
