<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class RentalProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'rental';
    }

    public function getName(): string
    {
        return 'Rental Product';
    }

    public function getDescription(): string
    {
        return 'Temporary physical or equipment leases.';
    }

    public function requiresShipping(): bool
    {
        return true;
    }

    public function supportsInventory(): bool
    {
        return true;
    }
}
