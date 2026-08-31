<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

echo "=== POSTGRESQL MULTI-PROCESS CONCURRENCY HARNESS ===\n";

$tenant = Tenant::firstOrCreate(['slug' => 'pg-concurrency-tenant'], ['name' => 'PG Concurrency Tenant', 'status' => 'active']);

$product = app(CreateProductAction::class)->execute(new ProductData(
    tenantId: $tenant->id,
    productType: 'physical',
    sku: 'PG-CONCURRENT-SKU-'.uniqid(),
    translations: ['en' => ['name' => 'PG Concurrent Item']],
));

$wh = Warehouse::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'PG-WH-CONC'], ['name' => 'PG Wh', 'country_code' => 'CH']);
$src = InventorySource::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'PG-SRC-CONC'], ['warehouse_id' => $wh->id, 'name' => 'PG Src']);

$stockItem = StockItem::create([
    'tenant_id' => $tenant->id,
    'inventory_source_id' => $src->id,
    'product_id' => $product->id,
    'on_hand' => '1.0000',
    'reserved' => '0.0000',
    'backorder_mode' => 'deny',
]);

echo "Initial Stock: on_hand = {$stockItem->on_hand}, reserved = {$stockItem->reserved}\n";

$workerScript = sprintf(
    '<?php
    use Illuminate\Contracts\Console\Kernel;
    require "%s/vendor/autoload.php";
    $app = require_once "%s/bootstrap/app.php";
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $service = app(\Modules\Inventory\Contracts\InventoryReservationServiceInterface::class);
    $context = new \Modules\Inventory\DTOs\InventoryContext(tenantId: %d);
    $res = $service->reserve(%d, $argv[1], %d, null, \Modules\Inventory\ValueObjects\Quantity::fromString("1.0000"), $context);
    echo $res->isSuccess ? "SUCCESS" : "FAILED";
    ',
    dirname(__DIR__),
    dirname(__DIR__),
    $tenant->id,
    $tenant->id,
    $product->id
);

$tmp = sys_get_temp_dir().'/pg_worker_'.uniqid().'.php';
file_put_contents($tmp, $workerScript);

echo "Spawning 2 concurrent processes competing for 1 unit...\n";
$p1 = proc_open("php {$tmp} pg-res-1", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
$p2 = proc_open("php {$tmp} pg-res-2", [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);

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
echo "Final Stock: on_hand = {$stockItem->on_hand}, reserved = {$stockItem->reserved}\n";

$results = [$out1, $out2];
sort($results);

if ($results === ['FAILED', 'SUCCESS'] && $stockItem->reserved === '1.0000') {
    echo ">>> CONCURRENCY VERIFICATION PASSED: Exactly 1 process succeeded, 1 blocked and failed, 0 overselling! <<<\n";
    exit(0);
} else {
    echo ">>> CONCURRENCY VERIFICATION FAILED! <<<\n";
    exit(1);
}
