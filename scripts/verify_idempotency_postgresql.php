<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
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

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo "=== POSTGRESQL MULTI-PROCESS IDEMPOTENCY HARNESS ===\n";

$tenant = Tenant::firstOrCreate(['slug' => 'pg-idempotency-tenant'], ['name' => 'PG Idempotency Tenant', 'status' => 'active']);

$product = app(CreateProductAction::class)->execute(new ProductData(
    tenantId: $tenant->id,
    productType: 'physical',
    sku: 'PG-IDEM-SKU-'.uniqid(),
    translations: ['en' => ['name' => 'PG Idem Item']],
));

$wh = Warehouse::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'PG-WH-IDEM'], ['name' => 'PG Wh Idem', 'country_code' => 'CH']);
$src = InventorySource::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'PG-SRC-IDEM'], ['warehouse_id' => $wh->id, 'name' => 'PG Src Idem']);

$stockItem = StockItem::create([
    'tenant_id' => $tenant->id,
    'inventory_source_id' => $src->id,
    'product_id' => $product->id,
    'on_hand' => '0.0000',
    'reserved' => '0.0000',
]);

// ----------------------------------------------------
// 1. CONCURRENT DUPLICATE CLAIM TEST
// ----------------------------------------------------
echo "\n--- SCENARIO 1: CONCURRENT DUPLICATE CLAIM ---\n";
$idemKey1 = 'PG-CONCURRENT-KEY-'.uniqid();

$workerScript = sprintf(
    '<?php
    use Illuminate\Contracts\Console\Kernel;
    require "%s/vendor/autoload.php";
    $app = require_once "%s/bootstrap/app.php";
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $service = app(\Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface::class);
    $item = \Modules\Inventory\Models\StockItem::find(%d);
    try {
        $res = $service->receive($item, \Modules\Inventory\ValueObjects\Quantity::fromString("10.0000"), null, null, "%s");
        echo $res ? "DONE" : "FAIL";
    } catch (\Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage();
    }
    ',
    dirname(__DIR__),
    dirname(__DIR__),
    $stockItem->id,
    $idemKey1
);

$tmp1 = sys_get_temp_dir().'/pg_idem_worker_'.uniqid().'.php';
file_put_contents($tmp1, $workerScript);

echo "Spawning 2 concurrent processes executing receive +10 with same idempotency key...\n";
$p1 = proc_open("php {$tmp1}", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
$p2 = proc_open("php {$tmp1}", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);

$out1 = trim((string) stream_get_contents($pipes1[1]));
fclose($pipes1[1]);
proc_close($p1);

$out2 = trim((string) stream_get_contents($pipes2[1]));
fclose($pipes2[1]);
proc_close($p2);

@unlink($tmp1);

echo "Process 1 Result: {$out1}\n";
echo "Process 2 Result: {$out2}\n";

$stockItem->refresh();
$movementsCount1 = InventoryMovement::where('stock_item_id', $stockItem->id)->count();
$keysCount1 = InventoryOperationKey::where('idempotency_key', $idemKey1)->count();
$opKey1 = InventoryOperationKey::where('idempotency_key', $idemKey1)->first();

echo "Stock: on_hand = {$stockItem->on_hand}\n";
echo "Movements recorded: {$movementsCount1}, Operation keys: {$keysCount1}, Status: {$opKey1->status}\n";

if ($out1 !== 'DONE' || $out2 !== 'DONE' || $stockItem->on_hand !== '10.0000' || $movementsCount1 !== 1 || $keysCount1 !== 1 || $opKey1->status !== 'completed') {
    echo ">>> SCENARIO 1 FAILED! <<<\n";
    exit(1);
}
echo ">>> SCENARIO 1 PASSED <<<\n";

// ----------------------------------------------------
// 2. FAILED-THEN-RETRY CLAIM TEST
// ----------------------------------------------------
echo "\n--- SCENARIO 2: FAILED OPERATION RETRY ---\n";
$idemService = app(InventoryIdempotencyService::class);
$adjService = app(InventoryAdjustmentServiceInterface::class);
$idemKey2 = 'PG-FAILED-KEY-'.uniqid();

$failedAttempts = 0;
$failingAction = function () use (&$failedAttempts, $adjService, $stockItem) {
    $failedAttempts++;
    if ($failedAttempts === 1) {
        throw new RuntimeException('Simulated transient PostgreSQL deadlock/failure.');
    }

    return $adjService->receive($stockItem, Quantity::fromString('5.0000'));
};

try {
    $idemService->execute($tenant->id, $idemKey2, 'receive', 'stock_items', (string) $stockItem->id, $failingAction);
} catch (RuntimeException $e) {
    echo 'First attempt correctly threw: '.$e->getMessage()."\n";
}

$stockItem->refresh();
echo "Stock after failure: on_hand = {$stockItem->on_hand} (no change)\n";

$res2 = $idemService->execute($tenant->id, $idemKey2, 'receive', 'stock_items', (string) $stockItem->id, $failingAction);
$stockItem->refresh();
$opKey2 = InventoryOperationKey::where('idempotency_key', $idemKey2)->first();

echo "Stock after retry: on_hand = {$stockItem->on_hand}, Status = {$opKey2->status}\n";

if ($stockItem->on_hand !== '15.0000' || $opKey2->status !== 'completed') {
    echo ">>> SCENARIO 2 FAILED! <<<\n";
    exit(1);
}
echo ">>> SCENARIO 2 PASSED <<<\n";

// ----------------------------------------------------
// 3. EXPIRED LEASE TAKEOVER TEST
// ----------------------------------------------------
echo "\n--- SCENARIO 3: ABANDONED LEASE TAKEOVER ---\n";
$idemKey3 = 'PG-EXPIRED-KEY-'.uniqid();

InventoryOperationKey::create([
    'tenant_id' => $tenant->id,
    'idempotency_key' => $idemKey3,
    'operation_type' => 'receive',
    'resource_type' => 'stock_items',
    'resource_id' => (string) $stockItem->id,
    'status' => 'processing',
    'lease_expires_at' => Carbon::now()->subMinutes(5),
    'created_at' => Carbon::now()->subMinutes(5),
]);

$idemService->execute(
    $tenant->id,
    $idemKey3,
    'receive',
    'stock_items',
    (string) $stockItem->id,
    fn () => $adjService->receive($stockItem, Quantity::fromString('5.0000'))
);

$stockItem->refresh();
$opKey3 = InventoryOperationKey::where('idempotency_key', $idemKey3)->first();

echo "Stock after takeover: on_hand = {$stockItem->on_hand}, Status = {$opKey3->status}\n";

if ($stockItem->on_hand !== '20.0000' || $opKey3->status !== 'completed') {
    echo ">>> SCENARIO 3 FAILED! <<<\n";
    exit(1);
}
echo ">>> SCENARIO 3 PASSED <<<\n";

echo "\n>>> ALL POSTGRESQL IDEMPOTENCY HARNESS SCENARIOS PASSED WITH 100% PRECISION! <<<\n";
exit(0);
