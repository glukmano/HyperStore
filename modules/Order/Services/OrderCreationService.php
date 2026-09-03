<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Enums\ReservationOwnerType;
use Modules\Order\Contracts\BusinessTimezoneResolverInterface;
use Modules\Order\Contracts\OrderCreationConcurrencyBarrierInterface;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\Contracts\OrderIdempotencyServiceInterface;
use Modules\Order\Contracts\OrderNumberGeneratorInterface;
use Modules\Order\Contracts\OrderOwnershipServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\DTOs\OrderCreationResultDTO;
use Modules\Order\Enums\FulfillmentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\PaymentStatus;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Exceptions\CheckoutNotReadyException;
use Modules\Order\Exceptions\CheckoutReadySnapshotMissingException;
use Modules\Order\Exceptions\ReservationAdoptionFailedException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\OrderStatusHistory;
use Throwable;

class OrderCreationService implements OrderCreationServiceInterface
{
    private readonly OrderCreationConcurrencyBarrierInterface $concurrencyBarrier;

    private OrderSnapshotValidator $snapshotValidator;

    public function __construct(
        private readonly OrderIdempotencyServiceInterface $idempotencyService,
        private readonly BusinessTimezoneResolverInterface $timezoneResolver,
        private readonly OrderNumberGeneratorInterface $numberGenerator,
        private readonly OrderOwnershipServiceInterface $ownershipService,
        private readonly InventoryReservationServiceInterface $inventoryReservationService,
        ?OrderCreationConcurrencyBarrierInterface $concurrencyBarrier = null,
        ?OrderSnapshotValidator $snapshotValidator = null
    ) {
        $this->concurrencyBarrier = $concurrencyBarrier ?? new NoOpOrderCreationConcurrencyBarrier;
        $this->snapshotValidator = $snapshotValidator ?? new OrderSnapshotValidator;
    }

    public function createFromCheckout(OrderCreationDTO $dto): OrderCreationResultDTO
    {
        app(TenantLicenseServiceInterface::class)->assertActiveForTenant($dto->tenantId);
        // 1. If idempotency key is provided, route through durable idempotency service for fingerprint enforcement
        if ($dto->idempotencyKey !== null && trim($dto->idempotencyKey) !== '') {
            $payload = [
                'checkout_id' => $dto->checkoutId,
                'actor_type' => $dto->actorType->value,
                'actor_id' => $dto->actorId,
            ];

            $response = $this->idempotencyService->execute(
                tenantId: $dto->tenantId,
                checkoutId: $dto->checkoutId,
                orderId: null,
                operationType: 'create_order',
                idempotencyKey: $dto->idempotencyKey,
                requestPayload: $payload,
                callback: function () use ($dto): array {
                    return $this->executeOrderCreationTransaction($dto);
                }
            );

            $orderId = (int) $response['order_id'];
            /** @var Order $order */
            $order = Order::query()->where('id', $orderId)->with(['items', 'statusHistory'])->firstOrFail();

            $isReplay = (bool) ($response['is_replay'] ?? false);
            $guestAccessToken = ! $isReplay ? ($response['guest_access_token'] ?? null) : null;

            if (! $isReplay) {
                event(new OrderCreated($order));
            }

            return new OrderCreationResultDTO(
                order: $order,
                guestAccessToken: is_string($guestAccessToken) ? $guestAccessToken : null,
                isReplay: $isReplay
            );
        }

        // 2. If no idempotency key is provided, check existing order or execute transaction
        /** @var Order|null $existingOrder */
        $existingOrder = Order::query()
            ->where('tenant_id', $dto->tenantId)
            ->where('checkout_id', $dto->checkoutId)
            ->with(['items', 'statusHistory'])
            ->first();

        if ($existingOrder !== null) {
            return new OrderCreationResultDTO(
                order: $existingOrder,
                guestAccessToken: null,
                isReplay: true
            );
        }

        try {
            $response = DB::transaction(function () use ($dto): array {
                return $this->executeOrderCreationTransaction($dto);
            });

            $orderId = (int) $response['order_id'];
            /** @var Order $order */
            $order = Order::query()->where('id', $orderId)->with(['items', 'statusHistory'])->firstOrFail();

            event(new OrderCreated($order));

            return new OrderCreationResultDTO(
                order: $order,
                guestAccessToken: is_string($response['guest_access_token'] ?? null) ? $response['guest_access_token'] : null,
                isReplay: (bool) ($response['is_replay'] ?? false)
            );
        } catch (QueryException $e) {
            // Concurrent race on UNIQUE(tenant_id, checkout_id) resolved in fresh query outside aborted transaction
            /** @var Order|null $winnerOrder */
            $winnerOrder = Order::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('checkout_id', $dto->checkoutId)
                ->with(['items', 'statusHistory'])
                ->first();

            if ($winnerOrder !== null) {
                return new OrderCreationResultDTO(
                    order: $winnerOrder,
                    guestAccessToken: null,
                    isReplay: true
                );
            }

            throw $e;
        }
    }

    /**
     * @return array{order_id: int, order_number: string, guest_access_token: string|null, is_replay: bool}
     *
     * @throws CheckoutNotReadyException
     * @throws CheckoutReadySnapshotMissingException
     * @throws ReservationAdoptionFailedException
     */
    private function executeOrderCreationTransaction(OrderCreationDTO $dto): array
    {
        /** @var Order|null $existingOrder */
        $existingOrder = Order::query()
            ->where('tenant_id', $dto->tenantId)
            ->where('checkout_id', $dto->checkoutId)
            ->first();

        if ($existingOrder !== null) {
            return [
                'order_id' => $existingOrder->id,
                'order_number' => $existingOrder->order_number,
                'guest_access_token' => null,
                'is_replay' => true,
            ];
        }

        /** @var CheckoutSession|null $checkout */
        $checkout = CheckoutSession::query()
            ->where('id', $dto->checkoutId)
            ->where('tenant_id', $dto->tenantId)
            ->lockForUpdate()
            ->first();

        if ($checkout === null || $checkout->state !== 'ready_for_order') {
            throw CheckoutNotReadyException::forState($dto->checkoutId, $checkout->state ?? 'missing');
        }

        if ($checkout->expires_at->isPast()) {
            throw CheckoutNotReadyException::forState($dto->checkoutId, 'expired');
        }

        // 2. Consume ONLY the immutable ready_snapshot (Zero fallback to mutable checkout fields)
        $validatedSnapshot = $this->snapshotValidator->validate($checkout->id, $checkout->ready_snapshot);

        // 3. Resolve authoritative business timezone and generate atomic sequential order number
        $businessTz = $this->timezoneResolver->resolve(
            marketId: $validatedSnapshot['context']['market_id'],
            storeId: $validatedSnapshot['context']['store_id']
        );
        $orderNumber = $this->numberGenerator->generate($dto->tenantId, $businessTz);

        // 4. Generate guest access token if guest order
        $plainGuestToken = null;
        $guestTokenHash = null;
        if ($checkout->user_id === null) {
            $plainGuestToken = $this->ownershipService->generateGuestAccessToken();
            $guestTokenHash = hash('sha256', $plainGuestToken);
        }

        // 5. Create Order header
        $orderUuid = (string) Str::uuid();

        /** @var Order $order */
        $order = Order::create([
            'uuid' => $orderUuid,
            'tenant_id' => $dto->tenantId,
            'checkout_id' => $checkout->id,
            'order_number' => $orderNumber,
            'store_id' => $validatedSnapshot['context']['store_id'],
            'market_id' => $validatedSnapshot['context']['market_id'],
            'channel_id' => $validatedSnapshot['context']['channel_id'],
            'user_id' => $checkout->user_id,
            'guest_token_hash' => $guestTokenHash,
            'currency' => $validatedSnapshot['context']['currency'],
            'locale' => $validatedSnapshot['context']['locale'],
            'order_status' => OrderStatus::PLACED->value,
            'payment_status' => PaymentStatus::PENDING->value,
            'fulfillment_status' => FulfillmentStatus::UNFULFILLED->value,
            'merchandise_subtotal_minor' => $validatedSnapshot['totals']['merchandise_subtotal_minor'],
            'discount_total_minor' => $validatedSnapshot['totals']['discount_total_minor'],
            'shipping_total_minor' => $validatedSnapshot['totals']['shipping_total_minor'],
            'tax_total_minor' => $validatedSnapshot['totals']['tax_total_minor'],
            'grand_total_minor' => $validatedSnapshot['totals']['grand_total_minor'],
            'commercial_model_snapshot' => $validatedSnapshot['context']['commercial_model_snapshot'],
            'customer_snapshot' => $validatedSnapshot['customer_data'],
            'shipping_address_snapshot' => $validatedSnapshot['shipping_address'],
            'billing_address_snapshot' => $validatedSnapshot['billing_address'],
            'pricing_snapshot' => $validatedSnapshot['pricing_snapshot'],
            'tax_snapshot' => $validatedSnapshot['tax_snapshot'],
            'promotion_snapshot' => $validatedSnapshot['promotion_snapshot'],
            'shipping_snapshot' => $validatedSnapshot['selected_shipping_quote'],
            'fulfillment_snapshot' => $validatedSnapshot['fulfillment_snapshot'],
            'reservation_references' => $validatedSnapshot['reservation_references'],
            'version' => 1,
            'placed_at' => now(),
        ]);

        // 6. Create Order Items from validated lines
        foreach ($validatedSnapshot['lines'] as $line) {
            OrderItem::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $dto->tenantId,
                'order_id' => $order->id,
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'sku_snapshot' => $line['sku_snapshot'],
                'name_snapshot' => $line['name_snapshot'],
                'product_type_snapshot' => $line['product_type_snapshot'],
                'requires_shipping_snapshot' => $line['requires_shipping_snapshot'],
                'quantity' => $line['quantity'],
                'unit_price_minor' => $line['unit_price_minor'],
                'subtotal_minor' => $line['subtotal_minor'],
                'line_discount_minor' => $line['line_discount_minor'],
                'allocated_cart_discount_minor' => $line['allocated_cart_discount_minor'],
                'discount_minor' => $line['discount_minor'],
                'taxable_amount_minor' => $line['taxable_amount_minor'],
                'tax_minor' => $line['tax_minor'],
                'total_minor' => $line['total_minor'],
                'tax_class_id' => $line['tax_class_id'],
                'tax_rate_percent' => $line['tax_rate_percent'],
                'selected_options_snapshot' => $line['selected_options'],
                'customization_metadata_snapshot' => $line['customization_metadata'],
                'vendor_uuid_snapshot' => $line['vendor_uuid_snapshot'] ?? null,
                'vendor_name_snapshot' => $line['vendor_name_snapshot'] ?? null,
                'vendor_listing_uuid_snapshot' => $line['vendor_listing_uuid_snapshot'] ?? null,
                'commission_basis_minor' => $line['commission_basis_minor'] ?? null,
                'commission_rate_bps' => $line['commission_rate_bps'] ?? null,
                'commission_fixed_fee_minor' => $line['commission_fixed_fee_minor'] ?? null,
                'commission_amount_minor' => $line['commission_amount_minor'] ?? null,
                'commission_currency' => $line['commission_currency'] ?? null,
                'commission_rule_ref' => $line['commission_rule_ref'] ?? null,
                'vendor_id' => $line['vendor_id'] ?? null,
                'vendor_listing_id' => $line['vendor_listing_id'] ?? null,
            ]);
        }

        // 7. Record initial status history
        OrderStatusHistory::create([
            'tenant_id' => $dto->tenantId,
            'order_id' => $order->id,
            'status_dimension' => 'order',
            'from_status' => 'none',
            'to_status' => OrderStatus::PLACED->value,
            'reason' => 'Initial order placement from ready checkout session',
            'actor_type' => $dto->actorType->value,
            'actor_id' => $dto->actorId,
            'metadata' => [
                'checkout_id' => $checkout->id,
                'order_number' => $orderNumber,
            ],
        ]);

        // 8. Atomically adopt all Inventory Reservations
        foreach ($validatedSnapshot['reservation_references'] as $ref) {
            $resKey = (string) $ref['reservation_key'];

            $this->concurrencyBarrier->beforeReservationAdoption($dto->tenantId, $resKey);

            try {
                $this->inventoryReservationService->adopt(
                    tenantId: $dto->tenantId,
                    reservationKey: $resKey,
                    ownerType: ReservationOwnerType::ORDER,
                    ownerReference: $order->uuid
                );
            } catch (Throwable $e) {
                throw ReservationAdoptionFailedException::forKey($resKey, $e->getMessage());
            }
        }

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'guest_access_token' => $plainGuestToken,
            'is_replay' => false,
        ];
    }
}
