<?php

declare(strict_types=1);

namespace Modules\B2B\Contracts;

use Modules\Cart\Models\Cart;

/**
 * Owner Delta §3: the ONLY path by which a negotiated B2B price reaches
 * Checkout — resolved entirely server-side from the accepted, non-expired
 * `Quote`/`QuoteLine` a Cart's lines trace back to via `cart_lines.
 * quote_line_id`. There is no client-suppliable `cart_line_id => price`
 * input anywhere in this contract or its callers.
 */
interface QuoteLinePriceResolverInterface
{
    /**
     * @return array<int, int> cart_line_id => negotiated_unit_price_minor
     */
    public function resolveNegotiatedPricesForCart(Cart $cart): array;
}
