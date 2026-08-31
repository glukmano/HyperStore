<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class WholesaleProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'wholesale';
    }

    public function getName(): string
    {
        return 'Wholesale Bulk Product';
    }

    public function getDescription(): string
    {
        return 'High-volume B2B catalog items with tier requirements.';
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
