<?php

declare(strict_types=1);

namespace KirchDev\Pbac\Organisation;

use KirchDev\Pbac\Contracts\OrganisationResolver;

class DefaultOrganisationResolver implements OrganisationResolver
{
    private int|string|null $organisationId = null;

    public function getOrganisationId(): int|string|null
    {
        return $this->organisationId;
    }

    public function setOrganisationId(int|string|null $organisationId): void
    {
        $this->organisationId = $organisationId;
    }

    public function clearOrganisationId(): void
    {
        $this->organisationId = null;
    }
}
