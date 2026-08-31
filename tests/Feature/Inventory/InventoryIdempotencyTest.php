<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryOperationKey;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\InventoryIdempotencyService;
use Modules\Inventory\ValueObjects\Quantity;
use RuntimeException;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'idempotency-test-tenant', 'name' => 'Idempotency Tenant', 'status' => 'active']);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'IDEMPOTENT-SKU-1',
        translations: ['en' => ['name' => 'Idempotent Product']],
    ));

    $this->wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'IDEM-WH-1', 'name' => 'Idem Wh', 'country_code' => 'CH']);
    $this->src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh->id, 'code' => 'IDEM-SRC-1', 'name' => 'Idem Src']);

    $this->stockItem = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->src->id,
        'product_id' => $this->product->id,
        'on_hand' => '0.0000',
    ]);
});

test('Claim-first idempotency prevents duplicate stock addition on retried calls', function (): void {
    $service = app(InventoryAdjustmentServiceInterface::class);

    $m1 = $service->receive($this->stockItem, Quantity::fromString('15.0000'), idempotencyKey: 'RECEIVE-KEY-100');
    $this->stockItem->refresh();
    expect($this->stockItem->on_hand)->toBe('15.0000');

    $m2 = $service->receive($this->stockItem, Quantity::fromString('15.0000'), idempotencyKey: 'RECEIVE-KEY-100');
    $this->stockItem->refresh();

    expect($this->stockItem->on_hand)->toBe('15.0000')
        ->and($m1->id)->toBe($m2->id);

    $movementsCount = InventoryMovement::where('stock_item_id', $this->stockItem->id)->count();
    $keysCount = InventoryOperationKey::where('idempotency_key', 'RECEIVE-KEY-100')->count();

    expect($movementsCount)->toBe(1)
        ->and($keysCount)->toBe(1);
});

test('Crash-boundary test: mutation and completion occur in one atomic transaction', function (): void {
    $idemService = app(InventoryIdempotencyService::class);
    $adjService = app(InventoryAdjustmentServiceInterface::class);

    $failOnce = true;
    $action = function () use (&$failOnce, $adjService) {
        $movement = $adjService->receive($this->stockItem, Quantity::fromString('10.0000'));
        if ($failOnce) {
            $failOnce = false;
            throw new RuntimeException('Simulated crash right after mutation but before transaction commit.');
        }

        return $movement;
    };

    // First attempt fails inside transaction
    expect(fn () => $idemService->execute($this->tenant->id, 'CRASH-BOUNDARY-KEY', 'receive', 'stock_items', (string) $this->stockItem->id, $action))
        ->toThrow(RuntimeException::class, 'Simulated crash');

    // Verify atomic rollback: on_hand must still be 0.0000 and status must NOT be completed
    $this->stockItem->refresh();
    expect($this->stockItem->on_hand)->toBe('0.0000');

    $opKey = InventoryOperationKey::where('idempotency_key', 'CRASH-BOUNDARY-KEY')->first();
    expect($opKey->status)->toBe('failed')
        ->and($opKey->completed_at)->toBeNull();

    // Subsequent retry with SAME key succeeds
    $result = $idemService->execute($this->tenant->id, 'CRASH-BOUNDARY-KEY', 'receive', 'stock_items', (string) $this->stockItem->id, $action);

    $this->stockItem->refresh();
    expect($this->stockItem->on_hand)->toBe('10.0000');

    $opKey->refresh();
    expect($opKey->status)->toBe('completed')
        ->and($opKey->completed_at)->not->toBeNull();

    $movementsCount = InventoryMovement::where('stock_item_id', $this->stockItem->id)->count();
    expect($movementsCount)->toBe(1);
});

test('Abandoned processing claim with expired lease is atomically recovered and completed', function (): void {
    $idemService = app(InventoryIdempotencyService::class);
    $adjService = app(InventoryAdjustmentServiceInterface::class);

    // Simulate an abandoned processing claim with an expired lease
    InventoryOperationKey::create([
        'tenant_id' => $this->tenant->id,
        'idempotency_key' => 'EXPIRED-LEASE-KEY',
        'operation_type' => 'receive',
        'resource_type' => 'stock_items',
        'resource_id' => (string) $this->stockItem->id,
        'status' => 'processing',
        'lease_expires_at' => Carbon::now()->subMinutes(5), // Expired
        'created_at' => Carbon::now()->subMinutes(5),
    ]);

    // Subsequent caller takes over expired claim and completes mutation
    $result = $idemService->execute(
        $this->tenant->id,
        'EXPIRED-LEASE-KEY',
        'receive',
        'stock_items',
        (string) $this->stockItem->id,
        fn () => $adjService->receive($this->stockItem, Quantity::fromString('30.0000'))
    );

    $this->stockItem->refresh();
    expect($this->stockItem->on_hand)->toBe('30.0000');

    $opKey = InventoryOperationKey::where('idempotency_key', 'EXPIRED-LEASE-KEY')->first();
    expect($opKey->status)->toBe('completed');
});

test('Non-unique QueryException is strictly rethrown without text matching fallback', function (): void {
    $idemService = app(InventoryIdempotencyService::class);

    expect(fn () => $idemService->execute(
        $this->tenant->id,
        'NON-UNIQUE-ERROR-KEY',
        'receive',
        'stock_items',
        (string) $this->stockItem->id,
        function () {
            // Trigger a database syntax/table error (non-unique constraint)
            DB::select('SELECT * FROM non_existent_table_xyz');
        }
    ))->toThrow(QueryException::class);
});
