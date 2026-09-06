<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Contracts;

use Modules\Order\Models\Order;

/**
 * Non-invasive Order-creation hook (the Affiliate pattern, reused
 * verbatim) — grants Digital/License entitlements for every eligible
 * OrderItem, guarded by app()->bound() and wrapped in try/catch since a
 * fulfillment-side grant failure must never block real Order creation
 * (unlike B2B credit, which is a hard gate).
 */
interface DigitalEntitlementGrantHookInterface
{
    public function grantForOrder(Order $order): void;
}
