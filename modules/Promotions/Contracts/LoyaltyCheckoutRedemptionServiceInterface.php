<?php

declare(strict_types=1);

namespace Modules\Promotions\Contracts;

use Modules\Customers\Models\CustomerProfile;
use Modules\Promotions\Models\Coupon;

interface LoyaltyCheckoutRedemptionServiceInterface
{
    public function redeemForCheckout(
        CustomerProfile $customerProfile,
        int $tenantId,
        int $points,
        string $currency,
        string $checkoutSessionUuid,
    ): Coupon;

    public function cancelForCheckout(string $checkoutSessionUuid, int $tenantId): void;
}
