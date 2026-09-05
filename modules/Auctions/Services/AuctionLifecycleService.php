<?php

declare(strict_types=1);

namespace Modules\Auctions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Enums\UnpaidWinnerPolicy;
use Modules\Auctions\Models\Auction;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;

final class AuctionLifecycleService
{
    public function __construct(
        private readonly AuctionInventoryReservationService $inventoryReservationService,
        private readonly AuctionSettlementService $settlementService,
    ) {}

    public function activateScheduled(int $tenantId): int
    {
        $dbNow = DatabaseClock::now();
        $count = 0;

        Auction::where('tenant_id', $tenantId)
            ->where('status', AuctionStatus::Scheduled)
            ->where('starts_at', '<=', $dbNow)
            ->get()
            ->each(function (Auction $auction) use (&$count): void {
                DB::transaction(function () use ($auction, &$count): void {
                    /** @var Auction|null $locked */
                    $locked = Auction::where('id', $auction->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== AuctionStatus::Scheduled) {
                        return;
                    }

                    $this->inventoryReservationService->reserveAtActivation($locked);
                    $this->ensurePlaceholderPrice($locked);
                    $locked->update(['status' => AuctionStatus::Active]);
                    $count++;
                });
            });

        return $count;
    }

    public function closeEnded(int $tenantId): int
    {
        $dbNow = DatabaseClock::now();
        $count = 0;

        Auction::where('tenant_id', $tenantId)
            ->where('status', AuctionStatus::Active)
            ->where('ends_at', '<=', $dbNow)
            ->get()
            ->each(function (Auction $auction) use (&$count): void {
                $this->settlementService->closeAuction($auction);
                $count++;
            });

        return $count;
    }

    /**
     * Owner Delta §8: the sole configured policy is `cancel` — a winner
     * who does not complete Checkout within `winner_payment_window_minutes`
     * loses the win; the reserved lot is released.
     */
    public function cancelUnpaidWinners(int $tenantId): int
    {
        $dbNow = DatabaseClock::now();
        $count = 0;

        Auction::where('tenant_id', $tenantId)
            ->where('status', AuctionStatus::Ended)
            ->where('unpaid_winner_policy', UnpaidWinnerPolicy::Cancel->value)
            ->get()
            ->each(function (Auction $auction) use (&$count, $dbNow): void {
                $deadline = $auction->ends_at->addMinutes($auction->winner_payment_window_minutes);
                if ($dbNow->lte($deadline)) {
                    return;
                }

                DB::transaction(function () use ($auction, &$count): void {
                    /** @var Auction|null $locked */
                    $locked = Auction::where('id', $auction->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== AuctionStatus::Ended) {
                        return;
                    }

                    $this->inventoryReservationService->release($locked);
                    $locked->update(['status' => AuctionStatus::Cancelled]);
                    $count++;
                });
            });

        return $count;
    }

    /**
     * The auctioned Product's real, authoritative price is ALWAYS the
     * winning bid — resolved directly from the Cart line's own metadata by
     * `CheckoutPricingOrchestrator`, never from a PriceBook. However, a
     * separate, pre-existing pricing lookup
     * (`CheckoutShippingOrchestrator::quote()`, used only for shipping
     * rate-rule/weight evaluation, never the charged total) independently
     * requires SOME resolvable price to exist for the Product. This
     * ensures a minimal placeholder exists — it is never the amount
     * actually charged.
     */
    private function ensurePlaceholderPrice(Auction $auction): void
    {
        $hasPrice = Price::where('tenant_id', $auction->tenant_id)
            ->where('product_id', $auction->product_id)
            ->where('currency', $auction->currency)
            ->exists();
        if ($hasPrice) {
            return;
        }

        $priceBook = PriceBook::firstOrCreate(
            ['tenant_id' => $auction->tenant_id, 'code' => 'AUCTION_PLACEHOLDER_'.$auction->currency],
            ['name' => 'Auction Placeholder ('.$auction->currency.')', 'currency' => $auction->currency, 'status' => 'active', 'priority' => -1000]
        );

        Price::create([
            'tenant_id' => $auction->tenant_id,
            'price_book_id' => $priceBook->id,
            'product_id' => $auction->product_id,
            'amount_minor' => $auction->starting_price_minor,
            'currency' => $auction->currency,
            'status' => 'active',
        ]);
    }
}
