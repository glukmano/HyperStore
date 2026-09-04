<?php

use App\Core\Context\ContextManager;
use App\Core\Context\Middleware\ResolveContextMiddleware;
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
use Modules\Inventory\Registries\InventorySourceTypeRegistry;
use Modules\Inventory\Services\InventoryReconciliationService;
use Modules\Inventory\ValueObjects\Quantity;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

Route::middleware(['api', 'auth:sanctum,web', ResolveContextMiddleware::class])->prefix('api/v1/inventory')->name('api.v1.inventory.')->group(function () {

    $getTenantId = function (): int {
        $tenant = app(ContextManager::class)->getTenant();
        $id = $tenant->getId();
        if ($id === null) {
            throw new UnauthorizedHttpException('Tenant', 'Tenant context required.');
        }

        return (int) $id;
    };

    // 1. Warehouses
    Route::get('warehouses', function () use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! request()->user()?->can('warehouses.view') && ! request()->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(['data' => Warehouse::where('tenant_id', $tenantId)->get()]);
    });

    Route::post('warehouses', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('warehouses.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'type' => ['nullable', 'string', 'in:fulfillment_center,retail_store,distribution_center,hub'],
            'ownership_type' => ['nullable', 'string', 'in:platform,vendor,3pl'],
            'vendor_id' => ['nullable', 'integer', 'required_if:ownership_type,vendor'],
        ]);
        $wh = Warehouse::create(array_merge($data, ['tenant_id' => $tenantId]));

        return response()->json(['data' => $wh], 201);
    });

    // 2. Inventory Sources
    Route::get('sources', function () use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! request()->user()?->can('inventory.view') && ! request()->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(['data' => InventorySource::where('tenant_id', $tenantId)->with('warehouse')->get()]);
    });

    Route::post('sources', function (Request $request, InventorySourceTypeRegistry $registry) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', 'string'],
            'warehouse_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'integer'],
        ]);

        if (! $registry->has($data['source_type'])) {
            return response()->json(['error' => "Invalid source type [{$data['source_type']}]."], 422);
        }

        // Validate warehouse ownership if passed
        if (! empty($data['warehouse_id'])) {
            Warehouse::where('tenant_id', $tenantId)->findOrFail($data['warehouse_id']);
        }

        $source = InventorySource::create(array_merge($data, ['tenant_id' => $tenantId]));

        return response()->json(['data' => $source], 201);
    });

    // 3. Stock Items
    Route::get('stock-items', function () use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! request()->user()?->can('inventory.view') && ! request()->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(['data' => StockItem::where('tenant_id', $tenantId)->paginate(25)]);
    });

    // 4. Availability check
    Route::post('availability', function (Request $request, InventoryAvailabilityServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
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
    Route::post('reservations/reserve', function (Request $request, InventoryReservationServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.reserve') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'reservation_key' => ['required', 'string', 'max:255'],
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'string'],
            'ttl_minutes' => ['nullable', 'integer'],
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

        $result = $service->reserve(
            tenantId: $tenantId,
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

    Route::post('reservations/release', function (Request $request, InventoryReservationServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.reserve') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate(['reservation_key' => ['required', 'string']]);
        $success = $service->release($tenantId, $data['reservation_key'], $request->header('X-Idempotency-Key'));

        return response()->json(['success' => $success]);
    });

    Route::post('reservations/commit', function (Request $request, InventoryReservationServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.reserve') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate(['reservation_key' => ['required', 'string']]);
        $success = $service->commit($tenantId, $data['reservation_key'], $request->header('X-Idempotency-Key'));

        return response()->json(['success' => $success]);
    });

    // 6. Adjustments & Receiving
    Route::post('adjustments', function (Request $request, InventoryAdjustmentServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.adjust') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'stock_item_id' => ['required', 'integer'],
            'delta' => ['required', 'string'],
            'movement_type' => ['required', 'string', 'in:adjustment_in,adjustment_out,damaged,correction,recount'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var StockItem $item */
        $item = StockItem::where('tenant_id', $tenantId)->findOrFail($data['stock_item_id']);
        $movement = $service->adjust(
            stockItem: $item,
            delta: Quantity::fromString($data['delta']),
            movementType: $data['movement_type'],
            reason: $data['reason'] ?? null,
            idempotencyKey: $request->header('X-Idempotency-Key')
        );

        return response()->json(['data' => $movement], 201);
    });

    Route::post('receive', function (Request $request, InventoryAdjustmentServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.adjust') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'stock_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'string'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var StockItem $item */
        $item = StockItem::where('tenant_id', $tenantId)->findOrFail($data['stock_item_id']);
        $movement = $service->receive(
            stockItem: $item,
            quantity: Quantity::fromString($data['quantity']),
            referenceId: $data['reference'] ?? null,
            idempotencyKey: $request->header('X-Idempotency-Key')
        );

        return response()->json(['data' => $movement], 201);
    });

    // 7. Movements
    Route::get('movements', function () use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! request()->user()?->can('inventory.movements.view') && ! request()->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(['data' => InventoryMovement::where('tenant_id', $tenantId)->latest('created_at')->paginate(25)]);
    });

    // 8. Transfers Lifecycle
    Route::post('transfers/create', function (Request $request, InventoryTransferServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.transfer.create') && ! $request->user()?->can('inventory.transfer') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'transfer_number' => ['required', 'string', 'max:100'],
            'source_inventory_source_id' => ['required', 'integer'],
            'destination_inventory_source_id' => ['required', 'integer', 'different:source_inventory_source_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.requested_quantity' => ['required', 'string'],
            'idempotency_key' => ['nullable', 'string'],
        ]);

        /** @var list<array{product_id: int, product_variant_id: int|null, requested_quantity: string}> $items */
        $items = array_values(array_map(static fn (array $it): array => [
            'product_id' => (int) $it['product_id'],
            'product_variant_id' => isset($it['variant_id']) ? (int) $it['variant_id'] : null,
            'requested_quantity' => (string) $it['requested_quantity'],
        ], $data['items']));

        $transfer = $service->create(
            tenantId: $tenantId,
            sourceInventorySourceId: (int) $data['source_inventory_source_id'],
            destinationInventorySourceId: (int) $data['destination_inventory_source_id'],
            transferNumber: $data['transfer_number'],
            items: $items,
            idempotencyKey: $data['idempotency_key'] ?? null,
        );

        return response()->json(['data' => $transfer->load('items')], 201);
    });

    Route::post('transfers/dispatch', function (Request $request, InventoryTransferServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.transfer') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate(['transfer_id' => ['required', 'integer']]);
        /** @var InventoryTransfer $transfer */
        $transfer = InventoryTransfer::where('tenant_id', $tenantId)->findOrFail($data['transfer_id']);
        $success = $service->dispatch($transfer, $request->header('X-Idempotency-Key'));

        return response()->json(['success' => $success]);
    });

    Route::post('transfers/receive', function (Request $request, InventoryTransferServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.transfer') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'transfer_id' => ['required', 'integer'],
            'received_quantities' => ['nullable', 'array'],
        ]);
        /** @var InventoryTransfer $transfer */
        $transfer = InventoryTransfer::where('tenant_id', $tenantId)->findOrFail($data['transfer_id']);
        $success = $service->receive($transfer, $data['received_quantities'] ?? [], $request->header('X-Idempotency-Key'));

        return response()->json(['success' => $success]);
    });

    Route::post('transfers/cancel', function (Request $request, InventoryTransferServiceInterface $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('inventory.transfer') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate(['transfer_id' => ['required', 'integer']]);
        /** @var InventoryTransfer $transfer */
        $transfer = InventoryTransfer::where('tenant_id', $tenantId)->findOrFail($data['transfer_id']);
        $success = $service->cancel($transfer);

        return response()->json(['success' => $success]);
    });

    // 9. Reconciliation Preview
    Route::get('reconciliation', function (InventoryReconciliationService $service) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! request()->user()?->can('inventory.reconcile') && ! request()->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(['data' => $service->reconcile($tenantId)]);
    });
});
