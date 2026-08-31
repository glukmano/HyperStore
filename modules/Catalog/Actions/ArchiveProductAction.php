<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use App\Core\Audit\Contracts\AuditManagerInterface;
use Modules\Catalog\Events\ProductArchived;
use Modules\Catalog\Models\Product;

class ArchiveProductAction
{
    public function __construct(
        private readonly ?AuditManagerInterface $auditManager = null,
    ) {}

    public function execute(Product $product): Product
    {
        $product->update(['status' => 'archived']);

        // Hide from all active store listings
        $product->storeListings()->update(['status' => 'hidden']);

        $this->auditManager?->log(
            event: 'product.archived',
            subject: $product,
            properties: ['sku' => $product->sku]
        );

        ProductArchived::dispatch($product);

        return $product;
    }
}
