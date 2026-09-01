<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Modules\Catalog\Contracts\ProductShippingCapabilityResolverInterface;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\Models\Product;

class ProductShippingCapabilityResolver implements ProductShippingCapabilityResolverInterface
{
    public function __construct(
        private readonly ProductTypeRegistryInterface $productTypeRegistry
    ) {}

    public function requiresPhysicalShipping(Product $product): bool
    {
        $type = $this->productTypeRegistry->get($product->product_type);

        return $type->requiresShipping();
    }

    public function supportsInventory(Product $product): bool
    {
        $type = $this->productTypeRegistry->get($product->product_type);

        return $type->supportsInventory();
    }
}
