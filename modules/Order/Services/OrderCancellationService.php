<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Contracts\OrderIdempotencyServiceInterface;
use Modules\Order\Enums\FulfillmentStatus;
use Modules\Order\Enums\OrderActorType;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderCancelled;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Exceptions\InvalidOrderTransitionException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusHistory;

class OrderCancellationService implements OrderCancellationServiceInterface
{
    public function __construct(
        private readonly OrderIdempotencyServiceInterface $idempotencyService,
        private readonly InventoryReservationServiceInterface $inventoryReservationService
    ) {}

    public function cancel(
        Order $order,
        string $reason,
        OrderActorType $actorType = OrderActorType::CUSTOMER,
        ?int $actorId = null,
        ?string $idempotencyKey = null
    ): Order {
        $payload = [
            'reason' => $reason,
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
        ];

        $response = $this->idempotencyService->execute(
            tenantId: $order->tenant_id,
            checkoutId: null,
            orderId: $order->id,
            operationType: 'cancel_order',
            idempotencyKey: $idempotencyKey,
            requestPayload: $payload,
            callback: function () use ($order, $reason, $actorType, $actorId): array {
                return $this->executeCancellationTransaction($order, $reason, $actorType, $actorId);
            }
        );

        $orderId = (int) $response['order_id'];
        /** @var Order $freshOrder */
        $freshOrder = Order::query()->where('id', $orderId)->with(['items', 'statusHistory'])->firstOrFail();

        return $freshOrder;
    }

    /**
     * @return array{order_id: int, status: string}
     */
    private function executeCancellationTransaction(
        Order $order,
        string $reason,
        OrderActorType $actorType,
        ?int $actorId
    ): array {
        /** @var Order $lockedOrder */
        $lockedOrder = Order::query()->where('id', $order->id)->lockForUpdate()->firstOrFail();

        // 1. Idempotent check: if already cancelled, return immediately
        if ($lockedOrder->order_status === OrderStatus::CANCELLED->value) {
            return [
                'order_id' => $lockedOrder->id,
                'status' => 'cancelled',
            ];
        }

        // 2. Validate state machine allows cancellation
        if (in_array($lockedOrder->order_status, [OrderStatus::COMPLETED->value, OrderStatus::FAILED->value], true)) {
            throw InvalidOrderTransitionException::forTransition($lockedOrder->order_status, 'cancelled', 'order');
        }

        $fromStatus = $lockedOrder->order_status;

        // 3. Mutate Order status and fulfillment status (unfulfilled cancellation semantics)
        $lockedOrder->order_status = OrderStatus::CANCELLED->value;
        $lockedOrder->fulfillment_status = FulfillmentStatus::CANCELLED->value;
        $lockedOrder->cancelled_at = now();
        $lockedOrder->version++;
        $lockedOrder->save();

        // 4. Record status history
        OrderStatusHistory::create([
            'tenant_id' => $lockedOrder->tenant_id,
            'order_id' => $lockedOrder->id,
            'status_dimension' => 'order',
            'from_status' => $fromStatus,
            'to_status' => OrderStatus::CANCELLED->value,
            'reason' => $reason,
            'actor_type' => $actorType->value,
            'actor_id' => $actorId,
        ]);

        // 5. Release all retained inventory reservations (Zero swallow of exceptions)
        if (is_array($lockedOrder->reservation_references)) {
            foreach ($lockedOrder->reservation_references as $ref) {
                if (is_array($ref) && ! empty($ref['reservation_key'])) {
                    $resKey = (string) $ref['reservation_key'];
                    $this->inventoryReservationService->release(
                        tenantId: $lockedOrder->tenant_id,
                        reservationKey: $resKey
                    );
                }
            }
        }

        // 6. Dispatch events after commit
        DB::afterCommit(function () use ($lockedOrder, $fromStatus, $reason, $actorType, $actorId): void {
            OrderStatusChanged::dispatch(
                $lockedOrder,
                'order',
                $fromStatus,
                OrderStatus::CANCELLED->value,
                $reason,
                $actorType->value,
                $actorId
            );

            OrderCancelled::dispatch($lockedOrder, $reason);
        });

        return [
            'order_id' => $lockedOrder->id,
            'status' => 'cancelled',
        ];
    }
}
