<?php

declare(strict_types=1);

namespace Modules\Pricing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by PriceWriteService after a Price row is committed. Pricing
 * had zero domain events before Phase-17 — this is the first, added
 * specifically so Customer Engagement's price-drop alerts have a real
 * signal to subscribe to instead of polling (Master §26).
 */
class PriceChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly int $priceBookId,
        public readonly ?int $oldAmountMinor,
        public readonly int $newAmountMinor,
        public readonly string $currency,
    ) {}
}
