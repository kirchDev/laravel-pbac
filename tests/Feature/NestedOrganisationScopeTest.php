<?php

declare(strict_types=1);

use KirchDev\Pbac\Contracts\OrganisationResolver;
use KirchDev\Pbac\Facades\Pbac;

it('restores the outer organisation when an inner scope throws', function () {
    $resolver = app(OrganisationResolver::class);

    $caught = null;

    Pbac::withOrganisation(1, function () use (&$caught) {
        try {
            Pbac::withOrganisation(2, function () {
                throw new RuntimeException('inner blew up');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        expect(app(OrganisationResolver::class)->getOrganisationId())->toBe(1);
    });

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($resolver->getOrganisationId())->toBeNull();
});

it('restores after a withoutOrganisation block nested inside withOrganisation that throws', function () {
    $resolver = app(OrganisationResolver::class);
    $resolver->setOrganisationId(99);

    try {
        Pbac::withOrganisation(1, function () {
            Pbac::withoutOrganisation(function () {
                throw new RuntimeException('nope');
            });
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($resolver->getOrganisationId())->toBe(99);
});
