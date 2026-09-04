<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Support\QuantityScaleGuard;
use Modules\Inventory\ValueObjects\Quantity;
use Modules\Order\Contracts\ReturnPhysicalDispositionServiceInterface;
use Modules\Order\Enums\SellerReturnStatus;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\ReturnItem;
use Modules\Order\Models\SellerReturn;

/**
 * ADR-0128: restock triggers at physical disposition (received + inspected +
 * condition determined) — never at refund finalization. This service is the
 * ONLY place `return_items.restock_action` is consumed to mutate Inventory.
 */
class ReturnPhysicalDispositionService implements ReturnPhysicalDispositionServiceInterface
{
    private const array VALID_RESTOCK_ACTIONS = ['restock', 'quarantine', 'discard', 'return_to_supplier'];

    public function __construct(
        private readonly InventoryAdjustmentServiceInterface $inventoryAdjustmentService
    ) {}

    public function confirmPhysicalDisposition(
        int $tenantId,
        int $sellerReturnId,
        int $orderItemId,
        string $quantityReceived,
        string $condition,
        string $restockAction,
        ?int $destinationInventorySourceId = null
    ): SellerReturn {
        if (! in_array($restockAction, self::VALID_RESTOCK_ACTIONS, true)) {
            throw new InvalidArgumentException("Invalid restock_action [{$restockAction}].");
        }

        if (in_array($restockAction, ['restock', 'quarantine'], true) && $destinationInventorySourceId === null) {
            throw new InvalidArgumentException("restock_action [{$restockAction}] requires an explicit destination_inventory_source_id.");
        }

        // ADR-0129: fail closed on any quantity that cannot be exactly represented
        // at Inventory's scale-4 precision — never silently rounded.
        QuantityScaleGuard::assertScale4Representable($quantityReceived, 'quantity_received');

        return DB::transaction(function () use ($tenantId, $sellerReturnId, $orderItemId, $quantityReceived, $condition, $restockAction, $destinationInventorySourceId): SellerReturn {
            /** @var SellerReturn $sellerReturn */
            $sellerReturn = SellerReturn::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $sellerReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ReturnItem $returnItem */
            $returnItem = ReturnItem::query()
                ->where('tenant_id', $tenantId)
                ->where('seller_return_id', $sellerReturn->id)
                ->where('order_item_id', $orderItemId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent replay: a disposition already confirmed for this line
            // returns the SellerReturn unchanged rather than re-mutating Inventory.
            if ($returnItem->disposed_at !== null) {
                return $sellerReturn;
            }

            $approvedQty = BigDecimal::of((string) $returnItem->quantity_approved);
            $receivedQty = BigDecimal::of($quantityReceived);
            if ($receivedQty->compareTo($approvedQty) > 0) {
                throw new InvalidArgumentException("quantity_received [{$quantityReceived}] exceeds quantity_approved [{$returnItem->quantity_approved}] for ReturnItem [{$returnItem->id}].");
            }

            /** @var OrderItem $orderItem */
            $orderItem = OrderItem::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $orderItemId)
                ->firstOrFail();

            $dispositionOperationUuid = (string) Str::uuid();

            $returnItem->update([
                'quantity_received' => $quantityReceived,
                'condition' => $condition,
                'restock_action' => $restockAction,
                'disposition_operation_uuid' => $dispositionOperationUuid,
                'destination_inventory_source_id' => $destinationInventorySourceId,
                'disposed_at' => now(),
            ]);

            // Stable per-line idempotency identity (ADR-0128): seller_return_uuid +
            // return_item_id + disposition_operation_uuid — never keyed solely by
            // SellerReturn id, since one return can have multiple independently
            // dispositioned lines.
            $idempotencyKey = $sellerReturn->uuid.':'.$returnItem->id.':'.$dispositionOperationUuid;

            if ($restockAction === 'restock' && $receivedQty->isGreaterThan(BigDecimal::zero())) {
                $this->inventoryAdjustmentService->receiveByIdentity(
                    tenantId: $tenantId,
                    inventorySourceId: $destinationInventorySourceId,
                    productId: (int) $orderItem->product_id,
                    productVariantId: $orderItem->variant_id !== null ? (int) $orderItem->variant_id : null,
                    quantity: Quantity::fromString($quantityReceived),
                    referenceType: 'seller_return',
                    referenceId: $sellerReturn->uuid,
                    idempotencyKey: $idempotencyKey
                );
            } elseif ($restockAction === 'quarantine' && $receivedQty->isGreaterThan(BigDecimal::zero())) {
                $this->inventoryAdjustmentService->quarantineByIdentity(
                    tenantId: $tenantId,
                    inventorySourceId: $destinationInventorySourceId,
                    productId: (int) $orderItem->product_id,
                    productVariantId: $orderItem->variant_id !== null ? (int) $orderItem->variant_id : null,
                    quantity: Quantity::fromString($quantityReceived),
                    reason: "RMA quarantine disposition for SellerReturn [{$sellerReturn->uuid}]",
                    idempotencyKey: $idempotencyKey
                );
            }
            // discard / return_to_supplier: no sellable stock mutation by design (ADR-0128).

            if ($sellerReturn->received_at === null) {
                $sellerReturn->received_at = now();
            }

            $allDisposed = ! ReturnItem::query()
                ->where('tenant_id', $tenantId)
                ->where('seller_return_id', $sellerReturn->id)
                ->whereNull('disposed_at')
                ->exists();

            if ($allDisposed) {
                $sellerReturn->status = SellerReturnStatus::INSPECTED->value;
                $sellerReturn->inspected_at = now();
            } else {
                $sellerReturn->status = SellerReturnStatus::RECEIVED->value;
            }

            $sellerReturn->save();

            $fresh = $sellerReturn->fresh(['items']);

            return $fresh instanceof SellerReturn ? $fresh : $sellerReturn;
        });
    }
}
