<?php

declare(strict_types=1);

namespace Modules\B2B\Services;

use Modules\B2B\Contracts\QuoteLinePriceResolverInterface;
use Modules\B2B\Enums\QuoteStatus;
use Modules\B2B\Models\QuoteLine;
use Modules\Cart\Models\Cart;

/**
 * Owner Delta §3: re-validates the accepted, non-expired Quote status on
 * EVERY resolution (not only at acceptance time) — a Quote that later
 * expires or is rejected stops contributing a negotiated price on the very
 * next Checkout recalculation, exactly like every other authoritative
 * re-resolution already in the Checkout pipeline.
 */
final class QuoteLinePriceResolver implements QuoteLinePriceResolverInterface
{
    public function resolveNegotiatedPricesForCart(Cart $cart): array
    {
        $cart->loadMissing('lines');

        $quoteLineIds = $cart->lines->pluck('quote_line_id')->filter()->unique()->values();
        if ($quoteLineIds->isEmpty()) {
            return [];
        }

        $quoteLines = QuoteLine::whereIn('id', $quoteLineIds)
            ->with('quote')
            ->get()
            ->keyBy('id');

        $prices = [];
        foreach ($cart->lines as $line) {
            if ($line->quote_line_id === null) {
                continue;
            }

            /** @var QuoteLine|null $quoteLine */
            $quoteLine = $quoteLines->get($line->quote_line_id);
            if ($quoteLine === null || $quoteLine->quote === null) {
                continue;
            }
            if ($quoteLine->quote->status !== QuoteStatus::Accepted) {
                continue;
            }
            if ($quoteLine->negotiated_unit_price_minor === null) {
                continue;
            }

            $prices[$line->id] = $quoteLine->negotiated_unit_price_minor;
        }

        return $prices;
    }
}
