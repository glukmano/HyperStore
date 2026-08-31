<?php

use App\Core\Context\ContextManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Contracts\InventoryAvailabilityServiceInterface;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\InventoryReconciliationService;
use Modules\Inventory\ValueObjects\Quantity;

Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/inventory')->name('api.v1.inventory.')->group(function () {

    // 1. Warehouses
    Route::get('warehouses', function () {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return response()->json(['data' => Warehouse::where('tenant_id', $tenantId)->get()]);
    });

    Route::post('warehouses', function (Request $request) {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $data = $request->validate([
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);
        $wh = Warehouse::create(array_merge($data, ['tenant_id' => $tenantId]));

        return response()->json(['data' => $wh], 201);
    });

    // 2. Inventory Sources
    Route::get('sources', function () {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return response()->json(['data' => InventorySource::where('tenant_id', $tenantId)->with('warehouse')->get()]);
    });

    Route::post('sources', function (Request $request) {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $data = $request->validate([
            'code' => ['required', 'string'],
            'name' => ['required', 'string'],
            'source_type' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);
        $source = InventorySource::create(array_merge($data, ['tenant_id' => $tenantId]));

        return response()->json(['data' => $source], 201);
    });

    // 3. Stock Items
    Route::get('stock-items', function () {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return response()->json(['data' => StockItem::where('tenant_id', $tenantId)->paginate(25)]);
    });

    // 4. Availability check
    Route::post('availability', function (Request $request, InventoryAvailabilityServiceInterface $service) {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'store_id' => ['nullable', 'integer'],
            'market_id' => ['nullable', 'integer'],
            'channel_id' => ['nullable', 'integer'],
        ]);

        $context = new InventoryContext(
            tenantId: $tenantId,
            storeId: $data['store_id'] ?? null,
            marketId: $data['market_id'] ?? null,
            channelId: $data['channel_id'] ?? null
        );

        $result = $service->check($data['product_id'], $data['variant_id'] ?? null, $context);

        return response()->json([
            'data' => [
                'product_id' => $result->productId,
                'variant_id' => $result->variantId,
                'available_quantity' => $result->availableQuantity->toString(),
                'is_in_stock' => $result->isInStock,
                'is_backorderable' => $result->isBackorderable,
                'is_low_stock' => $result->isLowStock,
                'stock_status' => $result->stockStatus,
                'source_breakdown' => $result->sourceBreakdown,
            ],
        ]);
    });

    // 5. Reservations
    Route::post('reservations/reserve', function (Request $request, InventoryReservationServiceInterface $service) {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $data = $request->validate([
            'reservation_key' => ['required', 'string'],
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'string'],
            'ttl_minutes' => ['nullable', 'integer'],
        ]);

        $context = new InventoryContext(tenantId: $tenantId);
        $result = $service->reserve(
            reservationKey: $data['reservation_key'],
            productId: $data['product_id'],
            variantId: $data['variant_id'] ?? null,
            requestedQuantity: Quantity::fromString($data['quantity']),
            context: $context,
            ttlMinutes: $data['ttl_minutes'] ?? 15,
            idempotencyKey: $request->header('X-Idempotency-Key')
        );

        if (! $result->isSuccess) {
            return response()->json(['error' => $result->message], 422);
        }

        return response()->json([
            'data' => [
                'reservation_key' => $result->reservation?->reservation_key,
                'reserved_quantity' => $result->reservedQuantity->toString(),
                'status' => $result->reservation?->status,
                'expires_at' => $result->reservation?->expires_at?->toIso8601String(),
            ],
        ], 201);
    });

    Route::post('reservations/release', function (Request $request, InventoryReservationServiceInterface $service) {
        $data = $request->validate(['reservation_key' => ['required', 'string']]);
        $success = $service->release($data['reservation_key'], $request->header('X-Idempotency-Key'));

        return response()->json(['success' => $success]);
    });

    Route::post('reservations/commit', function (Request $request, InventoryReservationServiceInterface $service) {
        $data = $request->validate(['reservation_key' => ['required', 'string']]);
        $success = $service->commit($data['reservation_key'], $request->header('X-Idempotency-Key'));

        return response()->json(['success' => $success]);
    });

    // 6. Adjustments & Receiving
    Route::post('adjustments', function (Request $request, InventoryAdjustmentServiceInterface $service) {
        $data = $request->validate([
            'stock_item_id' => ['required', 'integer'],
            'delta' => ['required', 'string'],
            'movement_type' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        /** @var StockItem $item */
        $item = StockItem::findOrFail($data['stock_item_id']);
        $movement = $service->adjust(
            stockItem: $item,
            delta: Quantity::fromString($data['delta']),
            movementType: $data['movement_type'],
            reason: $data['reason'] ?? null,
            idempotencyKey: $request->header('X-Idempotency-Key')
        );

        return response()->json(['data' => $movement], 201);
    });

    Route::post('receive', function (Request $request, InventoryAdjustmentServiceInterface $service) {
        $data = $request->validate([
            'stock_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'string'],
            'reference' => ['nullable', 'string'],
        ]);

        /** @var StockItem $item */
        $item = StockItem::findOrFail($data['stock_item_id']);
        $movement = $service->receive(
            stockItem: $item,
            quantity: Quantity::fromString($data['quantity']),
            referenceId: $data['reference'] ?? null,
            idempotencyKey: $request->header('X-Idempotency-Key')
        );

        return response()->json(['data' => $movement], 201);
    });

    // 7. Movements
    Route::get('movements', function () {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return response()->json(['data' => InventoryMovement::where('tenant_id', $tenantId)->latest('created_at')->paginate(25)]);
    });

    // 8. Transfers
    Route::post('transfers/dispatch', function (Request $request, InventoryTransferServiceInterface $service) {
        $data = $request->validate(['transfer_id' => ['required', 'integer']]);
        /** @var InventoryTransfer $transfer */
        $transfer = InventoryTransfer::findOrFail($data['transfer_id']);
        $success = $service->dispatch($transfer, $request->header('X-Idempotency-Key'));

        return response()->json(['success' => $success]);
    });

    Route::post('transfers/receive', function (Request $request, InventoryTransferServiceInterface $service) {
        $data = $request->validate(['transfer_id' => ['required', 'integer']]);
        /** @var InventoryTransfer $transfer */
        $transfer = InventoryTransfer::findOrFail($data['transfer_id']);
        $success = $service->receive($transfer, $request->header('X-Idempotency-Key'));

        return response()->json(['success' => $success]);
    });

    // 9. Reconciliation Preview
    Route::get('reconciliation', function (InventoryReconciliationService $service) {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return response()->json(['data' => $service->reconcile($tenantId)]);
    });
});
