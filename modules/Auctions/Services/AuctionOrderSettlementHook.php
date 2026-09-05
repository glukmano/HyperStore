<?php

declare(strict_types=1);

namespace Modules\Auctions\Services;

use Modules\Auctions\Contracts\AuctionOrderSettlementHookInterface;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Models\Auction;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Models\Order;

final class AuctionOrderSettlementHook implements AuctionOrderSettlementHookInterface
{
    public function settleFromOrder(Order $order): void
    {
        $checkout = CheckoutSession::find($order->checkout_id);
        if ($checkout === null || $checkout->auction_id === null) {
            return;
        }

        /** @var Auction|null $auction */
        $auction = Auction::where('id', $checkout->auction_id)->first();
        if ($auction === null || $auction->status === AuctionStatus::Settled) {
            return;
        }

        $auction->update(['status' => AuctionStatus::Settled]);
    }
}
