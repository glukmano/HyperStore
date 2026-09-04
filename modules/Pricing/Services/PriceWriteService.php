<?php

declare(strict_types=1);

namespace Modules\Pricing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Pricing\Events\PriceChanged;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;

/**
 * The single write path for Price rows (Phase-17). Before this, the only
 * place a Price was ever created/updated was directly inside a Livewire
 * component (Modules\Pricing\Livewire\ProductPricingManager::savePrice()),
 * which is why Pricing had zero domain events — Master §26 rule 12 forbids
 * Livewire as the domain layer, so this centralizes the write and is what
 * now dispatches PriceChanged, never the Livewire component itself.
 */
final class PriceWriteService
{
    public function setPrice(
        int $tenantId,
        int $priceBookId,
        int $productId,
        ?int $variantId,
        int $amountMinor,
        ?int $compareAtMinor,
        ?int $costMinor,
    ): Price {
        $priceBook = PriceBook::query()->findOrFail($priceBookId);

        return DB::transaction(function () use ($tenantId, $priceBookId, $productId, $variantId, $amountMinor, $compareAtMinor, $costMinor, $priceBook): Price {
            $existing = Price::query()
                ->where('tenant_id', $tenantId)
                ->where('price_book_id', $priceBookId)
                ->where('product_id', $productId)
                ->where('product_variant_id', $variantId)
                ->first();

            $oldAmountMinor = $existing?->amount_minor;

            $price = Price::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'price_book_id' => $priceBookId,
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                ],
                [
                    'amount_minor' => $amountMinor,
                    'compare_at_minor' => $compareAtMinor,
                    'cost_minor' => $costMinor,
                    'currency' => $priceBook->currency,
                    'status' => 'active',
                ]
            );

            if ($oldAmountMinor === null || $oldAmountMinor !== $amountMinor) {
                DB::afterCommit(function () use ($tenantId, $productId, $variantId, $priceBookId, $oldAmountMinor, $amountMinor, $priceBook): void {
                    PriceChanged::dispatch($tenantId, $productId, $variantId, $priceBookId, $oldAmountMinor, $amountMinor, $priceBook->currency);
                });
            }

            return $price;
        });
    }
}
