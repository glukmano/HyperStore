<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class PhysicalProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'physical';
    }

    public function getName(): string
    {
        return 'Physical Product';
    }

    public function getDescription(): string
    {
        return 'Tangible goods requiring physical shipping and inventory.';
    }

    public function requiresShipping(): bool
    {
        return true;
    }

    public function supportsInventory(): bool
    {
        return true;
    }

    public function supportsVariants(): bool
    {
        return true;
    }
}
