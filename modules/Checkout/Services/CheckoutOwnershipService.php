<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use App\Core\Context\ContextManager;
use Modules\Checkout\Exceptions\CheckoutAccessDeniedException;
use Modules\Checkout\Models\CheckoutSession;

class CheckoutOwnershipService
{
    public function __construct(
        private readonly ContextManager $contextManager
    ) {}

    /**
     * Verifies that the current request/user owns the specified CheckoutSession.
     *
     * @throws CheckoutAccessDeniedException
     */
    public function verifyOwnership(CheckoutSession $session, ?string $rawGuestToken = null): void
    {
        $tenantId = (int) $this->contextManager->getTenant()->getId();
        if ($session->tenant_id !== $tenantId) {
            throw CheckoutAccessDeniedException::forCheckout($session->id);
        }

        // Authenticated customer check
        if ($this->contextManager->hasUser()) {
            $currentUserId = (int) $this->contextManager->getUser()->getId();
            if ($session->user_id === $currentUserId) {
                return;
            }
            throw CheckoutAccessDeniedException::forCheckout($session->id);
        }

        // Guest token check (via session guest_token_hash or cart guest_token_hash)
        $expectedHash = $session->guest_token_hash ?? $session->cart->guest_token_hash ?? null;
        if ($rawGuestToken !== null && $expectedHash !== null) {
            $hashed = hash('sha256', $rawGuestToken);
            if (hash_equals($expectedHash, $hashed)) {
                return;
            }
        }

        throw CheckoutAccessDeniedException::forCheckout($session->id);
    }
}
