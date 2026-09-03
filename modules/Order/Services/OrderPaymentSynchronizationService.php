<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Exceptions\InvalidOrderTransitionException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderStatusHistory;

class OrderPaymentSynchronizationService implements OrderPaymentSynchronizationServiceInterface
{
    private const ALLOWED_TRANSITIONS = [
        PaymentStatus::PENDING->value => [
            PaymentStatus::AUTHORIZED->value,
            PaymentStatus::PAID->value,
            PaymentStatus::VOIDED->value,
        ],
        PaymentStatus::AUTHORIZED->value => [
            PaymentStatus::PAID->value,
            PaymentStatus::VOIDED->value,
        ],
        PaymentStatus::PAID->value => [
            PaymentStatus::REFUNDED->value,
        ],
        PaymentStatus::REFUNDED->value => [],
        PaymentStatus::VOIDED->value => [],
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function syncPaymentStatus(
        int $tenantId,
        int $orderId,
        PaymentStatus $status,
        string $reason,
        array $metadata = []
    ): Order {
        return DB::transaction(function () use ($tenantId, $orderId, $status, $reason, $metadata): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->payment_status === $status->value) {
                return $lockedOrder;
            }

            $fromStatus = $lockedOrder->payment_status;
            $toStatus = $status->value;

            $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
            if (! in_array($toStatus, $allowed, true)) {
                throw InvalidOrderTransitionException::forTransition($fromStatus, $toStatus, 'payment');
            }

            $lockedOrder->payment_status = $toStatus;
            $lockedOrder->version++;
            $lockedOrder->save();

            OrderStatusHistory::create([
                'tenant_id' => $lockedOrder->tenant_id,
                'order_id' => $lockedOrder->id,
                'status_dimension' => 'payment',
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason,
                'actor_type' => (string) ($metadata['actor_type'] ?? 'system'),
                'actor_id' => isset($metadata['actor_id']) ? (int) $metadata['actor_id'] : null,
                'metadata' => $metadata,
            ]);

            DB::afterCommit(function () use ($lockedOrder, $fromStatus, $toStatus, $reason, $metadata): void {
                OrderStatusChanged::dispatch(
                    $lockedOrder,
                    'payment',
                    $fromStatus,
                    $toStatus,
                    $reason,
                    (string) ($metadata['actor_type'] ?? 'system'),
                    isset($metadata['actor_id']) ? (int) $metadata['actor_id'] : null
                );
            });

            return $lockedOrder;
        });
    }
}
