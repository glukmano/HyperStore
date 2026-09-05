<?php

declare(strict_types=1);

namespace Modules\Auctions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Exceptions\AuctionClosedException;
use Modules\Auctions\Exceptions\BidTooLowException;
use Modules\Auctions\Models\Auction;
use Modules\Auctions\Models\Bid;
use Modules\Customers\Models\CustomerProfile;

/**
 * Owner Delta §5/§6/§9: PostgreSQL-authoritative atomic bid placement.
 * `Auction` is row-locked for the whole comparison+write; `Bid` rows are
 * written once and never mutated afterward; the close/floor comparison
 * uses database time queried inside this same locked transaction.
 */
final class AuctionBiddingService
{
    public function placeBid(int $tenantId, int $auctionId, CustomerProfile $bidder, int $amountMinor): Bid
    {
        return DB::transaction(function () use ($tenantId, $auctionId, $bidder, $amountMinor): Bid {
            /** @var Auction $auction */
            $auction = Auction::where('tenant_id', $tenantId)->where('id', $auctionId)->lockForUpdate()->firstOrFail();

            $dbNow = DatabaseClock::now();

            if ($auction->status !== AuctionStatus::Active || $dbNow->gt($auction->ends_at)) {
                throw AuctionClosedException::forAuction($auctionId);
            }

            $floor = $auction->current_bid_id !== null
                ? $auction->current_price_minor + $auction->bid_increment_minor
                : $auction->starting_price_minor;

            if ($amountMinor < $floor) {
                throw BidTooLowException::forAmount($amountMinor, $floor);
            }

            $bid = Bid::create([
                'tenant_id' => $tenantId,
                'auction_id' => $auctionId,
                'customer_profile_id' => $bidder->id,
                'amount_minor' => $amountMinor,
                'placed_at' => $dbNow,
            ]);

            $auction->update([
                'current_bid_id' => $bid->id,
                'current_price_minor' => $amountMinor,
                'current_bidder_customer_profile_id' => $bidder->id,
                'version' => $auction->version + 1,
            ]);

            return $bid;
        });
    }
}
