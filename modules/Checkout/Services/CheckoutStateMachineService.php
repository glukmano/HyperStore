<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use InvalidArgumentException;
use Modules\Checkout\Contracts\CheckoutPrerequisiteResolverInterface;
use Modules\Checkout\Models\CheckoutSession;

class CheckoutStateMachineService
{
    public function __construct(
        private readonly CheckoutPrerequisiteResolverInterface $prerequisiteResolver
    ) {}

    /**
     * Asserts that transition from current state to target state satisfies capability prerequisites.
     */
    public function assertCanTransition(CheckoutSession $session, string $targetState): void
    {
        if ($session->isTerminal()) {
            throw new InvalidArgumentException("Cannot transition terminal CheckoutSession [{$session->id}] (current: {$session->state}).");
        }

        $cart = $session->cart;
        $capabilities = $this->prerequisiteResolver->resolveCartCapabilities($cart);

        $allowedTransitions = [
            'created' => ['customer_info_ready', 'cancelled', 'expired', 'failed'],
            'customer_info_ready' => ['address_ready', 'fulfillment_ready', 'review_ready', 'ready_for_order', 'cancelled', 'expired', 'failed'],
            'address_ready' => ['fulfillment_ready', 'shipping_ready', 'review_ready', 'ready_for_order', 'cancelled', 'expired', 'failed'],
            'fulfillment_ready' => ['shipping_ready', 'inventory_reserved', 'review_ready', 'ready_for_order', 'cancelled', 'expired', 'failed'],
            'shipping_ready' => ['inventory_reserved', 'review_ready', 'ready_for_order', 'cancelled', 'expired', 'failed'],
            'inventory_reserved' => ['review_ready', 'ready_for_order', 'cancelled', 'expired', 'failed'],
            'review_ready' => ['ready_for_order', 'cancelled', 'expired', 'failed'],
        ];

        $validNextStates = $allowedTransitions[$session->state] ?? [];
        if (! in_array($targetState, $validNextStates, true)) {
            throw new InvalidArgumentException("Invalid state transition from [{$session->state}] to [{$targetState}].");
        }

        // Validate capability-specific requirements before reaching ready_for_order
        if ($targetState === 'ready_for_order') {
            if ($session->customer_data === null) {
                throw new InvalidArgumentException('Customer data is required before finalizing checkout.');
            }

            if ($capabilities['requires_physical_shipping']) {
                if ($session->shipping_address === null) {
                    throw new InvalidArgumentException('Shipping address is required for physical products.');
                }
                if ($session->selected_shipping_quote === null) {
                    throw new InvalidArgumentException('Shipping rate selection is required for physical products.');
                }
            }

            if ($capabilities['requires_inventory'] && empty($session->reservation_references)) {
                throw new InvalidArgumentException('Inventory reservation is required before finalizing checkout.');
            }
        }
    }
}
