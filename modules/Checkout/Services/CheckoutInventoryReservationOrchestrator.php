<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Modules\Checkout\Models\CheckoutSession;
use Modules\Fulfillment\DTOs\FulfillmentGroup;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\FulfillmentPlan;
use Modules\Fulfillment\DTOs\FulfillmentReadiness;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\ValueObjects\Quantity;
use RuntimeException;

class CheckoutInventoryReservationOrchestrator
{
    public function __construct(
        private readonly InventoryReservationServiceInterface $inventoryReservationService
    ) {}

    /**
     * Executes atomic multi-source inventory reservations within the caller's outer PostgreSQL transaction.
     * Locks source allocations in deterministic order (source_id ASC, product_id ASC, variant_id ASC).
     *
     * @return array<int, array<string, mixed>> List of structured reservation reference arrays
     */
    public function reserve(CheckoutSession $session, FulfillmentPlan $plan): array
    {
        $tenantId = $session->tenant_id;
        $acquiredReferences = [];

        // Check if any group is unavailable
        foreach ($plan->groups as $group) {
            if ($group->readiness === FulfillmentReadiness::UNAVAILABLE) {
                throw new RuntimeException('Cannot reserve inventory: fulfillment plan contains unavailable items.');
            }
        }

        // 1. Flatten and extract physical allocations requiring stock
        $allocations = [];
        foreach ($plan->groups as $group) {
            /** @var FulfillmentGroup $group */
            if (! $group->isShippable || $group->inventorySourceId === null) {
                continue;
            }

            foreach ($group->items as $item) {
                /** @var FulfillmentItemLine $item */
                $allocations[] = [
                    'source_id' => $group->inventorySourceId,
                    'product_id' => $item->productId,
                    'variant_id' => $item->variantId,
                    'quantity' => $item->quantity,
                ];
            }
        }

        // 2. Sort deterministically: source_id ASC, product_id ASC, variant_id ASC
        usort($allocations, function ($a, $b) {
            if ($a['source_id'] !== $b['source_id']) {
                return $a['source_id'] <=> $b['source_id'];
            }
            if ($a['product_id'] !== $b['product_id']) {
                return $a['product_id'] <=> $b['product_id'];
            }

            return ($a['variant_id'] ?? 0) <=> ($b['variant_id'] ?? 0);
        });

        // 3. Execute reservations within the outer transaction
        $invCtx = new InventoryContext(
            tenantId: $tenantId,
            storeId: $session->store_id,
            marketId: $session->market_id,
            channelId: $session->channel_id,
            customerGroupId: null
        );

        foreach ($allocations as $idx => $alloc) {
            $resKey = "checkout:{$session->id}:alloc:{$alloc['source_id']}:{$alloc['product_id']}:{$idx}";

            $result = $this->inventoryReservationService->reserve(
                tenantId: $tenantId,
                reservationKey: $resKey,
                productId: $alloc['product_id'],
                variantId: $alloc['variant_id'],
                requestedQuantity: Quantity::fromInteger($alloc['quantity']),
                context: new InventoryContext(
                    tenantId: $tenantId,
                    storeId: $session->store_id,
                    marketId: $session->market_id,
                    channelId: $session->channel_id,
                    customerGroupId: null
                ),
                ttlMinutes: 60,
                idempotencyKey: $resKey
            );

            if (! $result->isSuccess || $result->reservation === null) {
                throw new RuntimeException("Inventory reservation failed for product [{$alloc['product_id']}] on source [{$alloc['source_id']}]: {$result->message}");
            }

            $acquiredReferences[] = [
                'reservation_id' => (int) $result->reservation->id,
                'reservation_key' => $resKey,
                'source_id' => $alloc['source_id'],
                'product_id' => $alloc['product_id'],
                'variant_id' => $alloc['variant_id'],
                'quantity' => (string) $alloc['quantity'],
            ];
        }

        return $acquiredReferences;
    }

    /**
     * Releases all held reservations for the checkout session idempotently.
     */
    public function releaseAll(CheckoutSession $session): void
    {
        $references = (array) ($session->reservation_references ?? []);
        if (empty($references)) {
            return;
        }

        foreach ($references as $ref) {
            $resKey = is_array($ref) ? ($ref['reservation_key'] ?? null) : null;
            if (! is_string($resKey)) {
                continue;
            }

            $this->inventoryReservationService->release($session->tenant_id, $resKey);
        }
    }
}
