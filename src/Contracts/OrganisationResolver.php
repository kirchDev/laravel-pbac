<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Contracts;

interface OrganisationResolver
{
    public function getOrganisationId(): int|string|null;

    public function setOrganisationId(int|string|null $organisationId): void;

    public function clearOrganisationId(): void;
}
