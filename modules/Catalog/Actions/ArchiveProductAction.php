<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Modules\Catalog\Events\ProductArchived;
use Modules\Catalog\Models\Product;

class ArchiveProductAction
{
    public function execute(Product $product): Product
    {
        $product->update(['status' => 'archived']);

        // Hide from all active store listings
        $product->storeListings()->update(['status' => 'hidden']);

        ProductArchived::dispatch($product);

        return $product;
    }
}
