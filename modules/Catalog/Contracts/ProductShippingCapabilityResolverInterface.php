<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

use Modules\Catalog\Models\Product;

interface ProductShippingCapabilityResolverInterface
{
    public function requiresPhysicalShipping(Product $product): bool;

    public function supportsInventory(Product $product): bool;
}
