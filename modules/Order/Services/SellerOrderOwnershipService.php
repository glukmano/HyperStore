<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Order\Contracts\SellerOrderOwnershipServiceInterface;
use Modules\Order\Exceptions\OrderAccessDeniedException;
use Modules\Order\Models\SellerOrder;

class SellerOrderOwnershipService implements SellerOrderOwnershipServiceInterface
{
    public function verifyOwnership(SellerOrder $sellerOrder, ?int $vendorId = null): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        // 1. Staff permission check
        if ($user !== null && ($user->can('orders.view') || $user->can('order.view') || $user->can('seller_orders.view'))) {
            return;
        }

        // 2. Vendor check
        if ($vendorId !== null || ($user !== null && isset($user->vendor_id))) {
            $effectiveVendorId = $vendorId ?? (int) $user->vendor_id;
            if ($sellerOrder->seller_type === 'vendor' && (int) $sellerOrder->vendor_id === $effectiveVendorId) {
                return;
            }
            throw OrderAccessDeniedException::denied();
        }

        // 3. Customer check
        $order = $sellerOrder->order;
        if ($user !== null && $order->user_id !== null && (int) $user->id === (int) $order->user_id) {
            return;
        }

        throw OrderAccessDeniedException::denied();
    }
}
