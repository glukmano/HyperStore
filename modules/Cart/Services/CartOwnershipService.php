<?php

declare(strict_types=1);

namespace Modules\Cart\Services;

use App\Core\Context\ContextManager;
use Modules\Cart\Exceptions\CartAccessDeniedException;
use Modules\Cart\Models\Cart;

class CartOwnershipService
{
    public function __construct(
        private readonly ContextManager $contextManager
    ) {}

    /**
     * Verifies that the current request/user owns the specified Cart.
     *
     * @throws CartAccessDeniedException
     */
    public function verifyOwnership(Cart $cart, ?string $rawGuestToken = null): void
    {
        $tenantId = (int) $this->contextManager->getTenant()->getId();
        if ($cart->tenant_id !== $tenantId) {
            throw CartAccessDeniedException::forCart($cart->id);
        }

        // Authenticated customer check
        if ($this->contextManager->hasUser()) {
            $currentUserId = (int) $this->contextManager->getUser()->getId();
            if ($cart->user_id === $currentUserId) {
                return;
            }
            throw CartAccessDeniedException::forCart($cart->id);
        }

        // Guest token check
        if ($rawGuestToken !== null && $cart->guest_token_hash !== null) {
            $hashed = hash('sha256', $rawGuestToken);
            if (hash_equals($cart->guest_token_hash, $hashed)) {
                return;
            }
        }

        throw CartAccessDeniedException::forCart($cart->id);
    }
}
