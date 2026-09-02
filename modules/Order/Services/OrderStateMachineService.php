<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderStateMachineServiceInterface;
use Modules\Order\DTOs\OrderTransitionDTO;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\StatusDimension;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Exceptions\InvalidOrderTransitionException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusHistory;

class OrderStateMachineService implements OrderStateMachineServiceInterface
{
    /**
     * Authoritative transition graph for Order dimension in Phase-08.
     *
     * @var array<string, list<string>>
     */
    private const ORDER_TRANSITION_GRAPH = [
        'placed' => ['confirmed', 'cancelled', 'failed'],
        'confirmed' => ['processing', 'cancelled'],
        'processing' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'failed' => [],
    ];

    public function transition(Order $order, OrderTransitionDTO $dto): Order
    {
        // Phase-08 explicitly owns only the ORDER dimension
        if ($dto->dimension !== StatusDimension::ORDER) {
            throw InvalidOrderTransitionException::unsupportedDimension($dto->dimension->value);
        }

        $fromStatus = $dto->fromStatus;
        $toStatus = $dto->toStatus;

        /** @var Order $transitionedOrder */
        $transitionedOrder = DB::transaction(function () use ($order, $fromStatus, $toStatus, $dto): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->where('id', $order->id)->lockForUpdate()->firstOrFail();

            // Strict optimistic lock / CAS check: fromStatus must match current locked status
            if ($lockedOrder->order_status !== $fromStatus) {
                throw InvalidOrderTransitionException::staleTransition($fromStatus, $lockedOrder->order_status);
            }

            $allowed = self::ORDER_TRANSITION_GRAPH[$fromStatus] ?? [];
            if (! in_array($toStatus, $allowed, true)) {
                throw InvalidOrderTransitionException::forTransition($fromStatus, $toStatus, 'order');
            }

            // Apply Order dimension mutation
            $lockedOrder->order_status = $toStatus;

            if ($toStatus === OrderStatus::CONFIRMED->value && $lockedOrder->confirmed_at === null) {
                $lockedOrder->confirmed_at = now();
            } elseif ($toStatus === OrderStatus::COMPLETED->value && $lockedOrder->completed_at === null) {
                $lockedOrder->completed_at = now();
            } elseif ($toStatus === OrderStatus::CANCELLED->value && $lockedOrder->cancelled_at === null) {
                $lockedOrder->cancelled_at = now();
            }

            $lockedOrder->version++;
            $lockedOrder->save();

            // Record immutable audit history
            OrderStatusHistory::create([
                'tenant_id' => $lockedOrder->tenant_id,
                'order_id' => $lockedOrder->id,
                'status_dimension' => 'order',
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $dto->reason,
                'actor_type' => $dto->actorType->value,
                'actor_id' => $dto->actorId,
                'metadata' => $dto->metadata,
            ]);

            return $lockedOrder;
        });

        // Dispatch domain event strictly after transaction commit
        OrderStatusChanged::dispatch(
            $transitionedOrder,
            'order',
            $fromStatus,
            $toStatus,
            $dto->reason,
            $dto->actorType->value,
            $dto->actorId
        );

        return $transitionedOrder;
    }
}
