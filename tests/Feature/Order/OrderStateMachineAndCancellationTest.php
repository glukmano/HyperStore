<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Exception;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\ReservationAdoptionResultDTO;
use Modules\Inventory\DTOs\ReservationResultDTO;
use Modules\Inventory\Enums\ReservationOwnerType;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;
use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\Contracts\OrderIdempotencyServiceInterface;
use Modules\Order\Contracts\OrderStateMachineServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\DTOs\OrderTransitionDTO;
use Modules\Order\Enums\FulfillmentStatus;
use Modules\Order\Enums\OrderActorType;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\StatusDimension;
use Modules\Order\Events\OrderCancelled;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Exceptions\IdempotencyFingerprintMismatchException;
use Modules\Order\Exceptions\InvalidOrderTransitionException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderOperationKey;
use Modules\Order\Services\OrderCancellationService;
use RuntimeException;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['name' => 'State Tenant', 'slug' => 'state-tenant', 'status' => 'active']);
    $this->market = Market::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'State Market',
        'code' => 'STATE-MKT',
        'is_active' => true,
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'en',
        'timezone' => 'Europe/Zurich',
    ]);
    $this->store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'State Store',
        'slug' => 'state-store',
        'status' => 'active',
    ]);
    $this->channel = Channel::create([
        'type' => 'website',
        'name' => 'State Channel',
        'handle' => 'state-web-'.uniqid(),
        'is_active' => true,
    ]);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'STATE-SKU-001',
        translations: ['en' => ['name' => 'State Product']],
    ));

    $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-ST', 'name' => 'State WH', 'country_code' => 'CH']);
    $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC-ST', 'name' => 'State Source', 'priority' => 10]);
    $this->stockItem = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $src->id, 'product_id' => $this->product->id, 'on_hand' => '10.0000', 'reserved' => '0.0000']);

    $this->invService = app(InventoryReservationServiceInterface::class);
    $this->invContext = new InventoryContext(tenantId: $this->tenant->id);

    $this->creationService = app(OrderCreationServiceInterface::class);
    $this->stateMachine = app(OrderStateMachineServiceInterface::class);
    $this->cancellationService = app(OrderCancellationServiceInterface::class);
});

function createTestOrder($test, array $reservationKeys = []): Order
{
    $cart = Cart::create([
        'tenant_id' => $test->tenant->id,
        'user_id' => null,
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'en',
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'status' => 'active',
    ]);

    $resRefs = array_map(fn ($k) => ['reservation_key' => $k, 'product_id' => $test->product->id, 'quantity' => '1.00000000'], $reservationKeys);

    $checkout = CheckoutSession::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $test->tenant->id,
        'cart_id' => $cart->id,
        'user_id' => null,
        'guest_token_hash' => hash('sha256', 'guest-token'),
        'store_id' => $test->store->id,
        'market_id' => $test->market->id,
        'channel_id' => $test->channel->id,
        'currency' => 'EUR',
        'locale' => 'en',
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'state' => 'ready_for_order',
        'ready_snapshot' => [
            'context' => [
                'store_id' => $test->store->id,
                'market_id' => $test->market->id,
                'channel_id' => $test->channel->id,
                'currency' => 'EUR',
                'locale' => 'en',
                'commercial_model_snapshot' => 'platform_as_merchant_of_record',
            ],
            'totals' => [
                'merchandise_subtotal' => 1000,
                'line_discounts' => 0,
                'cart_discounts' => 0,
                'shipping_original' => 0,
                'shipping_discount' => 0,
                'shipping_final' => 0,
                'tax_total' => 0,
                'grand_total' => 1000,
                'currency' => 'EUR',
            ],
            'lines' => [[
                'cart_line_id' => 9901,
                'product_id' => $test->product->id,
                'sku_snapshot' => 'STATE-SKU-001',
                'name_snapshot' => 'State Product',
                'product_type_snapshot' => 'physical',
                'requires_shipping_snapshot' => true,
                'quantity' => '1.00000000',
            ]],
            'pricing_snapshot' => [
                'lines' => [[
                    'cart_line_id' => 9901,
                    'product_id' => $test->product->id,
                    'variant_id' => null,
                    'quantity' => '1.00000000',
                    'unit_price_minor' => 1000,
                    'merchandise_line_subtotal_minor' => 1000,
                    'line_discount_minor' => 0,
                    'allocated_cart_discount_minor' => 0,
                    'taxable_amount_minor' => 1000,
                    'tax_minor' => 0,
                    'line_total_minor' => 1000,
                    'tax_class_id' => null,
                    'tax_rate_percent' => null,
                    'currency' => 'EUR',
                ]],
                'subtotal_minor' => 1000,
                'currency' => 'EUR',
            ],
            'customer_data' => ['email' => 'state@example.com'],
            'reservation_references' => $resRefs,
        ],
        'evaluated_cart_version' => 1,
        'version' => 1,
        'expires_at' => now()->addHour(),
    ]);

    return $test->creationService->createFromCheckout(new OrderCreationDTO(
        tenantId: $test->tenant->id,
        checkoutId: $checkout->id,
    ))->order;
}

// ---------------------------------------------------------------------------
// 1. Valid order lifecycle transitions: placed -> confirmed -> processing -> completed
// ---------------------------------------------------------------------------
test('order transitions along valid lifecycle path and records status history', function (): void {
    Event::fake([OrderStatusChanged::class]);

    $order = createTestOrder($this);

    expect($order->order_status)->toBe(OrderStatus::PLACED->value);

    // 1. placed -> confirmed
    $order = $this->stateMachine->transition($order, new OrderTransitionDTO(
        fromStatus: 'placed',
        toStatus: 'confirmed',
        dimension: StatusDimension::ORDER,
        reason: 'Payment confirmed by Phase-09',
        actorType: OrderActorType::SYSTEM
    ));
    expect($order->order_status)->toBe(OrderStatus::CONFIRMED->value)
        ->and($order->confirmed_at)->not->toBeNull();

    // 2. confirmed -> processing
    $order = $this->stateMachine->transition($order, new OrderTransitionDTO(
        fromStatus: 'confirmed',
        toStatus: 'processing',
        dimension: StatusDimension::ORDER,
        reason: 'Sent to fulfillment queue',
        actorType: OrderActorType::SYSTEM
    ));
    expect($order->order_status)->toBe(OrderStatus::PROCESSING->value);

    // 3. processing -> completed
    $order = $this->stateMachine->transition($order, new OrderTransitionDTO(
        fromStatus: 'processing',
        toStatus: 'completed',
        dimension: StatusDimension::ORDER,
        reason: 'All items delivered and verified',
        actorType: OrderActorType::STAFF,
        actorId: 99
    ));
    expect($order->order_status)->toBe(OrderStatus::COMPLETED->value)
        ->and($order->completed_at)->not->toBeNull();

    // Status history count: initial 'placed' + 3 transitions = 4
    expect($order->statusHistory)->toHaveCount(4);

    Event::assertDispatched(OrderStatusChanged::class, 3);
});

// ---------------------------------------------------------------------------
// 2. Stale fromStatus is rejected with typed conflict
// ---------------------------------------------------------------------------
test('stale fromStatus throws InvalidOrderTransitionException with staleTransition message', function (): void {
    $order = createTestOrder($this);

    // Attempt transition expecting 'confirmed', but order is currently 'placed'
    expect(fn () => $this->stateMachine->transition($order, new OrderTransitionDTO(
        fromStatus: 'confirmed',
        toStatus: 'processing',
        dimension: StatusDimension::ORDER
    )))->toThrow(InvalidOrderTransitionException::class, 'STALE_ORDER_TRANSITION');
});

// ---------------------------------------------------------------------------
// 3. Negative tests: Phase-08 rejects payment/fulfillment manual transitions
// ---------------------------------------------------------------------------
test('phase-08 rejects manual transitions on payment and fulfillment dimensions', function (): void {
    $order = createTestOrder($this);

    // Attempt payment transition -> rejected
    expect(fn () => $this->stateMachine->transition($order, new OrderTransitionDTO(
        fromStatus: 'pending',
        toStatus: 'paid',
        dimension: StatusDimension::PAYMENT
    )))->toThrow(InvalidOrderTransitionException::class, 'UNSUPPORTED_STATUS_DIMENSION');

    // Attempt fulfillment transition -> rejected
    expect(fn () => $this->stateMachine->transition($order, new OrderTransitionDTO(
        fromStatus: 'unfulfilled',
        toStatus: 'fulfilled',
        dimension: StatusDimension::FULFILLMENT
    )))->toThrow(InvalidOrderTransitionException::class, 'UNSUPPORTED_STATUS_DIMENSION');
});

// ---------------------------------------------------------------------------
// 4. OrderCancellationService cancels order and releases retained inventory reservations
// ---------------------------------------------------------------------------
test('cancellation sets cancelled status, releases reservations and updates stock', function (): void {
    Event::fake([OrderCancelled::class, OrderStatusChanged::class]);

    $resKey = 'cancel-res-'.uniqid();
    $this->invService->reserve($this->tenant->id, $resKey, $this->product->id, null, Quantity::fromString('2.0000'), $this->invContext, 60);

    expect($this->stockItem->fresh()->reserved)->toBe('2.0000');

    $order = createTestOrder($this, [$resKey]);

    // Reservation was adopted
    $res = InventoryReservation::where('reservation_key', $resKey)->first();
    expect($res->owner_type)->toBe('order')
        ->and($res->status)->toBe('active');

    // Cancel order
    $cancelledOrder = $this->cancellationService->cancel(
        order: $order,
        reason: 'Customer requested cancellation',
        actorType: OrderActorType::CUSTOMER,
        idempotencyKey: 'cancel-key-001'
    );

    expect($cancelledOrder->order_status)->toBe(OrderStatus::CANCELLED->value)
        ->and($cancelledOrder->fulfillment_status)->toBe(FulfillmentStatus::CANCELLED->value)
        ->and($cancelledOrder->cancelled_at)->not->toBeNull();

    // Reservation was released
    $resAfter = InventoryReservation::where('reservation_key', $resKey)->first();
    expect($resAfter->status)->toBe('released');

    // Stock was released to 0
    expect($this->stockItem->fresh()->reserved)->toBe('0.0000');

    Event::assertDispatched(OrderCancelled::class);
    Event::assertDispatched(OrderStatusChanged::class);
});

// ---------------------------------------------------------------------------
// 5. Cancellation Idempotency: same key returns same order, different payload throws conflict
// ---------------------------------------------------------------------------
test('cancellation idempotency returns same order on replay and throws conflict on payload change', function (): void {
    $order = createTestOrder($this);

    // 1st cancel
    $res1 = $this->cancellationService->cancel(
        order: $order,
        reason: 'First reason',
        actorType: OrderActorType::CUSTOMER,
        idempotencyKey: 'idem-cancel-key'
    );

    // 2nd cancel with same key + same reason -> succeeds idempotently
    $res2 = $this->cancellationService->cancel(
        order: $order,
        reason: 'First reason',
        actorType: OrderActorType::CUSTOMER,
        idempotencyKey: 'idem-cancel-key'
    );

    expect($res2->id)->toBe($res1->id)
        ->and($res2->order_status)->toBe('cancelled');

    // 3rd cancel with same key + different reason -> throws fingerprint mismatch
    expect(fn () => $this->cancellationService->cancel(
        order: $order,
        reason: 'Different reason',
        actorType: OrderActorType::CUSTOMER,
        idempotencyKey: 'idem-cancel-key'
    ))->toThrow(IdempotencyFingerprintMismatchException::class);
});

// ---------------------------------------------------------------------------
// 6. Completed order cannot be cancelled
// ---------------------------------------------------------------------------
test('completed order cannot be cancelled', function (): void {
    $order = createTestOrder($this);

    // Transition to completed
    $order->order_status = OrderStatus::COMPLETED->value;
    $order->save();

    expect(fn () => $this->cancellationService->cancel($order, 'Too late'))
        ->toThrow(InvalidOrderTransitionException::class);
});

// ---------------------------------------------------------------------------
// 7. Injected Inventory release exception rolls back cancellation completely
// ---------------------------------------------------------------------------
test('injected inventory release exception aborts cancellation and rolls back mutations', function (): void {
    $resKey = 'fail-release-res-'.uniqid();
    $this->invService->reserve($this->tenant->id, $resKey, $this->product->id, null, Quantity::fromString('1.0000'), $this->invContext, 60);

    $order = createTestOrder($this, [$resKey]);
    $historyCountBefore = $order->statusHistory()->count();

    // Mock Inventory service to throw during release
    $mockInv = $this->createMock(InventoryReservationServiceInterface::class);
    $mockInv->method('release')->willThrowException(new RuntimeException('INVENTORY_SERVICE_UNAVAILABLE'));

    $cancellationService = new OrderCancellationService(
        app(OrderIdempotencyServiceInterface::class),
        $mockInv
    );

    $idemKey = 'cancel-fail-key-'.uniqid();

    try {
        $cancellationService->cancel(
            order: $order,
            reason: 'Should fail',
            actorType: OrderActorType::CUSTOMER,
            idempotencyKey: $idemKey
        );
        $this->fail('Expected exception was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('INVENTORY_SERVICE_UNAVAILABLE');
    }

    // Final assertions: Order status remains placed, fulfillment unfulfilled, reservations active
    $fresh = $order->fresh();
    expect($fresh->order_status)->toBe(OrderStatus::PLACED->value)
        ->and($fresh->fulfillment_status)->toBe(FulfillmentStatus::UNFULFILLED->value)
        ->and($fresh->cancelled_at)->toBeNull()
        ->and($fresh->statusHistory()->count())->toBe($historyCountBefore);

    $res = InventoryReservation::where('reservation_key', $resKey)->first();
    expect($res->status)->toBe('active');
    expect($this->stockItem->fresh()->reserved)->toBe('1.0000');

    // Operation key marked failed safely
    $opKey = OrderOperationKey::where('tenant_id', $this->tenant->id)
        ->where('order_id', $order->id)
        ->where('idempotency_key', $idemKey)
        ->first();
    expect($opKey)->not->toBeNull()
        ->and($opKey->status)->toBe('failed')
        ->and($opKey->error_payload['error_class'])->toBe('RuntimeException');
});

// ---------------------------------------------------------------------------
// 8. Multi-reservation cancellation atomicity: partial failure rolls back all
// ---------------------------------------------------------------------------
test('multi-reservation cancellation atomicity: second reservation failure rolls back first release', function (): void {
    $resKeyA = 'multi-res-a-'.uniqid();
    $resKeyB = 'multi-res-b-'.uniqid();

    $this->invService->reserve($this->tenant->id, $resKeyA, $this->product->id, null, Quantity::fromString('1.0000'), $this->invContext, 60);
    $this->invService->reserve($this->tenant->id, $resKeyB, $this->product->id, null, Quantity::fromString('1.0000'), $this->invContext, 60);

    expect($this->stockItem->fresh()->reserved)->toBe('2.0000');

    $order = createTestOrder($this, [$resKeyA, $resKeyB]);

    // Construct decorator that succeeds on resKeyA and throws on resKeyB
    $realInv = $this->invService;
    $decoratingInv = new class($realInv, $resKeyB) implements InventoryReservationServiceInterface
    {
        public function __construct(
            private InventoryReservationServiceInterface $inner,
            private string $failingKey
        ) {}

        public function reserve(int $tenantId, string $reservationKey, int $productId, ?int $variantId, Quantity $requestedQuantity, InventoryContext $context, int $ttlMinutes = 15, ?string $idempotencyKey = null): ReservationResultDTO
        {
            return $this->inner->reserve($tenantId, $reservationKey, $productId, $variantId, $requestedQuantity, $context, $ttlMinutes, $idempotencyKey);
        }

        public function adopt(int $tenantId, string $reservationKey, ReservationOwnerType $ownerType, string $ownerReference): ReservationAdoptionResultDTO
        {
            return $this->inner->adopt($tenantId, $reservationKey, $ownerType, $ownerReference);
        }

        public function release(int $tenantId, string $reservationKey, ?string $idempotencyKey = null): bool
        {
            if ($reservationKey === $this->failingKey) {
                throw new RuntimeException("RELEASE_ERROR_ON_{$reservationKey}");
            }

            return $this->inner->release($tenantId, $reservationKey, $idempotencyKey);
        }

        public function commit(int $tenantId, string $reservationKey, ?string $idempotencyKey = null): bool
        {
            return $this->inner->commit($tenantId, $reservationKey, $idempotencyKey);
        }

        public function expire(InventoryReservation $reservation): bool
        {
            return $this->inner->expire($reservation);
        }
    };

    $cancellationService = new OrderCancellationService(
        app(OrderIdempotencyServiceInterface::class),
        $decoratingInv
    );

    try {
        $cancellationService->cancel($order, 'Multi-reservation cancel');
        $this->fail('Expected exception was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe("RELEASE_ERROR_ON_{$resKeyB}");
    }

    // Both reservations must remain ACTIVE (Res A was rolled back)
    expect(InventoryReservation::where('reservation_key', $resKeyA)->value('status'))->toBe('active');
    expect(InventoryReservation::where('reservation_key', $resKeyB)->value('status'))->toBe('active');

    // Reserved stock must remain 2.0000
    expect($this->stockItem->fresh()->reserved)->toBe('2.0000');

    // Order remains PLACED
    expect($order->fresh()->order_status)->toBe(OrderStatus::PLACED->value);
});
