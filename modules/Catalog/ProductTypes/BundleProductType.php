<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class BundleProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'bundle';
    }

    public function getName(): string
    {
        return 'Product Bundle';
    }

    public function getDescription(): string
    {
        return 'Composite package of multiple individual catalog items.';
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
