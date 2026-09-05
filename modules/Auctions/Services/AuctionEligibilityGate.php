<?php

declare(strict_types=1);

namespace Modules\Auctions\Services;

use Modules\Auctions\Contracts\AuctionEligibilityGateInterface;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Exceptions\AuctionNotEligibleForCartException;
use Modules\Auctions\Models\Auction;

final class AuctionEligibilityGate implements AuctionEligibilityGateInterface
{
    public function assertEligibleForCart(int $tenantId, int $productId): void
    {
        $blocked = Auction::where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->whereIn('status', [AuctionStatus::Scheduled, AuctionStatus::Active])
            ->exists();

        if ($blocked) {
            throw AuctionNotEligibleForCartException::forProduct($productId);
        }
    }
}
