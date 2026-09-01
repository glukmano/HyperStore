<?php

declare(strict_types=1);

namespace Modules\Pricing\Services;

use Carbon\Carbon;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PriceResult;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TierPrice;
use Modules\Pricing\ValueObjects\MoneyValue;

class PriceResolver implements PriceResolverInterface
{
    public function resolve(PricingItem $item, PricingContext $context): ?PriceResult
    {
        $now = $context->effectiveAt ? Carbon::instance($context->effectiveAt) : Carbon::now();

        // 1. Find matching Price Books in order of specificity and priority
        $books = PriceBook::query()
            ->where('tenant_id', $context->tenantId)
            ->where('currency', $context->currency)
            ->where('status', 'active')
            ->where(function ($q) use ($context) {
                if ($context->storeId !== null) {
                    $q->where('store_id', $context->storeId)->orWhereNull('store_id');
                }
                if ($context->marketId !== null) {
                    $q->where('market_id', $context->marketId)->orWhereNull('market_id');
                }
                if ($context->channelId !== null) {
                    $q->where('channel_id', $context->channelId)->orWhereNull('channel_id');
                }
                if ($context->customerGroupId !== null) {
                    $q->where('customer_group_id', $context->customerGroupId)->orWhereNull('customer_group_id');
                }
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now);
            })
            ->orderByDesc('priority')
            ->orderByDesc('is_default')
            ->get();

        if ($books->isEmpty()) {
            return null;
        }

        $bookIds = $books->pluck('id')->all();

        // 2. Query Prices: Check variant price first, then canonical product price
        $priceQuery = Price::query()
            ->select('prices.*')
            ->join('price_books', 'prices.price_book_id', '=', 'price_books.id')
            ->where('prices.tenant_id', $context->tenantId)
            ->whereIn('prices.price_book_id', $bookIds)
            ->where('prices.product_id', $item->productId)
            ->where('prices.currency', $context->currency)
            ->where('prices.status', 'active')
            ->where(function ($q) use ($now) {
                $q->whereNull('prices.valid_from')->orWhere('prices.valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('prices.valid_until')->orWhere('prices.valid_until', '>=', $now);
            })
            ->orderByDesc('price_books.priority')
            ->orderByDesc('price_books.is_default')
            ->with(['tierPrices', 'priceBook']);

        if ($item->variantId !== null) {
            $priceQuery->where(function ($q) use ($item) {
                $q->where('product_variant_id', $item->variantId)
                    ->orWhereNull('product_variant_id');
            })->orderByRaw('product_variant_id IS NOT NULL DESC');
        } else {
            $priceQuery->whereNull('product_variant_id');
        }

        /** @var Price|null $matchedPrice */
        $matchedPrice = $priceQuery->first();

        if ($matchedPrice === null) {
            return null;
        }

        $pb = $matchedPrice->priceBook;
        $pbName = ($pb instanceof PriceBook) ? $pb->name : 'Default';
        $pbCode = ($pb instanceof PriceBook) ? $pb->code : 'default';
        $appliedRules = ["Matched PriceBook: {$pbName} [{$pbCode}]"];
        $unitAmount = $matchedPrice->amount_minor;
        $appliedTierMin = null;

        // 3. Check Quantity Tier Breaks
        /** @var numeric-string $itemQtyStr */
        $itemQtyStr = (string) $item->quantity;
        if (bccomp($itemQtyStr, '1', 4) > 0 && $matchedPrice->tierPrices->isNotEmpty()) {
            /** @var TierPrice|null $matchedTier */
            $matchedTier = $matchedPrice->tierPrices
                ->filter(fn ($t) => $t instanceof TierPrice && $t->min_quantity <= $item->quantity && ($t->max_quantity === null || $t->max_quantity >= $item->quantity))
                ->sortByDesc('min_quantity')
                ->first();

            if ($matchedTier !== null) {
                $unitAmount = $matchedTier->amount_minor;
                $appliedTierMin = $matchedTier->min_quantity;
                $appliedRules[] = "Applied Quantity Tier: min {$matchedTier->min_quantity} units";
            }
        }

        return new PriceResult(
            productId: $item->productId,
            variantId: $item->variantId,
            unitPrice: MoneyValue::fromMinor($unitAmount, $context->currency),
            compareAtPrice: $matchedPrice->getCompareAtMoney(),
            costPrice: $matchedPrice->getCostMoney(),
            appliedPriceBookId: $matchedPrice->price_book_id,
            appliedTierMinQuantity: $appliedTierMin,
            appliedRules: $appliedRules
        );
    }
}
