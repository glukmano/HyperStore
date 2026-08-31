<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryOperationKey;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

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

echo "Initial Stock: on_hand = {$stockItem->on_hand}\n";

$idemKey = 'PG-CONCURRENT-KEY-'.uniqid();

$workerScript = sprintf(
    '<?php
    use Illuminate\Contracts\Console\Kernel;
    require "%s/vendor/autoload.php";
    $app = require_once "%s/bootstrap/app.php";
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $service = app(\Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface::class);
    $item = \Modules\Inventory\Models\StockItem::find(%d);
    $res = $service->receive($item, \Modules\Inventory\ValueObjects\Quantity::fromString("10.0000"), null, null, "%s");
    echo $res ? "DONE" : "FAIL";
    ',
    dirname(__DIR__),
    dirname(__DIR__),
    $stockItem->id,
    $idemKey
);

$tmp = sys_get_temp_dir().'/pg_idem_worker_'.uniqid().'.php';
file_put_contents($tmp, $workerScript);

echo "Spawning 2 concurrent processes executing receive +10 with identical idempotency key...\n";
$p1 = proc_open("php {$tmp}", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
$p2 = proc_open("php {$tmp}", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);

$out1 = stream_get_contents($pipes1[1]);
fclose($pipes1[1]);
proc_close($p1);

$out2 = stream_get_contents($pipes2[1]);
fclose($pipes2[1]);
proc_close($p2);

@unlink($tmp);

echo "Process 1 Result: {$out1}\n";
echo "Process 2 Result: {$out2}\n";

$stockItem->refresh();
echo "Final Stock: on_hand = {$stockItem->on_hand}\n";

$movementsCount = InventoryMovement::where('stock_item_id', $stockItem->id)->count();
$keysCount = InventoryOperationKey::where('idempotency_key', $idemKey)->count();

echo "Total movements recorded: {$movementsCount}\n";
echo "Total operation keys recorded: {$keysCount}\n";

if ($stockItem->on_hand === '10.0000' && $movementsCount === 1 && $keysCount === 1) {
    echo ">>> IDEMPOTENCY CONCURRENCY VERIFICATION PASSED: Exactly 1 stock mutation executed (10.0000, not 20.0000)! <<<\n";
    exit(0);
} else {
    echo ">>> IDEMPOTENCY CONCURRENCY VERIFICATION FAILED! <<<\n";
    exit(1);
}
