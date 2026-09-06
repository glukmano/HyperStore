<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\POS\DTOs\BarcodeMatch;
use Modules\POS\Exceptions\AmbiguousBarcodeException;

/**
 * Owner Delta §9: source-audited — products has unique(tenant_id, sku) but
 * NO uniqueness constraint on barcode; product_variants has no uniqueness
 * constraint on sku/barcode at all beyond unique(product_id,
 * combination_hash). Lookup is Tenant + Store-sellable scoped and REJECTS
 * as ambiguous rather than silently choosing the first row whenever more
 * than one active sellable Product/Variant matches within that scope.
 */
final class BarcodeLookupService
{
    public function resolve(int $tenantId, int $storeId, string $code): BarcodeMatch
    {
        $code = trim($code);

        $variantMatches = ProductVariant::query()
            ->whereHas('product', function ($q) use ($tenantId, $storeId): void {
                $q->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->whereHas('storeListings', function ($sl) use ($storeId): void {
                        $sl->where('store_id', $storeId)->where('status', 'published');
                    });
            })
            ->where('status', 'active')
            ->where(function ($q) use ($code): void {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->get(['id', 'product_id']);

        $productMatches = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('storeListings', function ($sl) use ($storeId): void {
                $sl->where('store_id', $storeId)->where('status', 'published');
            })
            ->where(function ($q) use ($code): void {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->get(['id']);

        $candidates = [];
        foreach ($variantMatches as $variant) {
            $candidates[] = new BarcodeMatch((int) $variant->product_id, (int) $variant->id);
        }
        foreach ($productMatches as $product) {
            $candidates[] = new BarcodeMatch((int) $product->id, null);
        }

        if (count($candidates) === 0) {
            throw new AmbiguousBarcodeException("No sellable Product/Variant found for code [{$code}] at Store [{$storeId}].");
        }

        if (count($candidates) > 1) {
            throw new AmbiguousBarcodeException("Code [{$code}] matches more than one sellable Product/Variant at Store [{$storeId}] — rejected as ambiguous.");
        }

        return $candidates[0];
    }
}
