<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\Order\Contracts\OrderOwnershipServiceInterface;
use Modules\Order\Exceptions\OrderAccessDeniedException;
use Modules\Order\Models\Order;

class OrderOwnershipService implements OrderOwnershipServiceInterface
{
    public function generateGuestAccessToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashGuestToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function verifyOwnership(Order $order, ?string $guestToken = null): void
    {
        // Authenticated customer order
        if ($order->user_id !== null) {
            $currentUserId = Auth::id();
            if ($currentUserId !== null && (int) $currentUserId === (int) $order->user_id) {
                return;
            }

            // Staff with permission may view
            /** @var User|null $user */
            $user = Auth::user();
            if ($user !== null && ($user->can('order.view') || $user->can('orders.view') || $user->can('order.manage'))) {
                return;
            }

            throw OrderAccessDeniedException::denied();
        }

        // Guest order: requires valid guest token matching hash
        if ($guestToken === null || $order->guest_token_hash === null) {
            throw OrderAccessDeniedException::denied();
        }

        $computedHash = $this->hashGuestToken($guestToken);
        if (! hash_equals($order->guest_token_hash, $computedHash)) {
            throw OrderAccessDeniedException::denied();
        }
    }
}
