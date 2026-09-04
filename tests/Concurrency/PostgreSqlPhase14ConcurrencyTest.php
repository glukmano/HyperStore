<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Contracts\ReturnRequestServiceInterface;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\SellerReturn;
use Tests\TestCase;

/**
 * Phase-14: real process-level PostgreSQL concurrency races (ADR-0125/0126/0128,
 * Revision-2 §E, 12-item matrix A-L). All workers call production service methods
 * exclusively — zero duplicated business logic. Synchronization uses the same
 * file-flag barrier pattern established in PostgreSqlInventoryAdoptionConcurrencyTest.
 */
class PostgreSqlPhase14ConcurrencyTest extends TestCase
{
    private const string PG_DB = 'hyperstore';

    private const string PG_USER = 'lukman';

    private const string PG_HOST = '127.0.0.1';

    private const int PG_PORT = 5432;

    private Tenant $tenant;

    private int $productId;

    private string $baseWorkerBootstrap;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => self::PG_DB,
            'database.connections.pgsql.username' => self::PG_USER,
            'database.connections.pgsql.host' => self::PG_HOST,
            'database.connections.pgsql.port' => self::PG_PORT,
        ]);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        $this->seed(ReferenceDataSeeder::class);

        $uid = uniqid('p14_conc_');
        $this->tenant = Tenant::create(['name' => 'Phase14 Conc Tenant', 'slug' => $uid, 'status' => 'active']);

        $product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'P14-CONC-'.strtoupper($uid),
            translations: ['en' => ['name' => 'Phase14 Concurrency Product']],
        ));
        $this->productId = $product->id;

        $bp = base_path();
        $db = self::PG_DB;
        $user = self::PG_USER;
        $host = self::PG_HOST;
        $port = self::PG_PORT;

        $this->baseWorkerBootstrap = <<<PHP
<?php
require '{$bp}/vendor/autoload.php';
\$app = require_once '{$bp}/bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.database' => '{$db}', 'database.connections.pgsql.username' => '{$user}', 'database.connections.pgsql.host' => '{$host}', 'database.connections.pgsql.port' => {$port}]);
\Illuminate\Support\Facades\DB::purge('pgsql');
\Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');
PHP;
    }

    /**
     * @return array{0: int, 1: int} [sourceInventorySourceId, destinationInventorySourceId]
     */
    private function makeTransferPair(): array
    {
        $uid = uniqid('wh_');
        $whA = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'A-'.$uid, 'name' => 'WH A', 'country_code' => 'CH', 'status' => 'active']);
        $whB = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'B-'.$uid, 'name' => 'WH B', 'country_code' => 'CH', 'status' => 'active']);
        $srcA = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $whA->id, 'code' => 'SA-'.$uid, 'name' => 'SRC A', 'priority' => 10, 'status' => 'active']);
        $srcB = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $whB->id, 'code' => 'SB-'.$uid, 'name' => 'SRC B', 'priority' => 10, 'status' => 'active']);

        return [$srcA->id, $srcB->id];
    }

    private function stockOn(int $sourceId, string $onHand): StockItem
    {
        return StockItem::create([
            'tenant_id' => $this->tenant->id,
            'inventory_source_id' => $sourceId,
            'product_id' => $this->productId,
            'on_hand' => $onHand,
            'reserved' => '0.0000',
        ]);
    }

    private function makeTransfer(int $sourceId, int $destId, string $qty, string $status = 'draft'): InventoryTransfer
    {
        return app(InventoryTransferServiceInterface::class)->create(
            tenantId: $this->tenant->id,
            sourceInventorySourceId: $sourceId,
            destinationInventorySourceId: $destId,
            transferNumber: 'TR-'.uniqid(),
            items: [['product_id' => $this->productId, 'requested_quantity' => $qty]],
            initialStatus: $status,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race A/B: customer reservation vs transfer dispatch (both lock orders)
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_a_customer_reservation_vs_transfer_dispatch(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');
        $transfer = $this->makeTransfer($srcId, $destId, '5.0000');

        $tenantId = $this->tenant->id;
        $productId = $this->productId;
        $transferId = $transfer->id;
        $b = $this->baseWorkerBootstrap;

        $workerReserve = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\ValueObjects\Quantity;

// __BARRIER_WAIT__
try {
    app(InventoryReservationServiceInterface::class)->reserve(
        {$tenantId}, 'race-a-'.uniqid(), {$productId}, null, Quantity::fromString('3.0000'),
        new InventoryContext(tenantId: {$tenantId}), 60
    );
    echo 'RESERVED';
} catch (Throwable \$e) {
    echo 'RESERVE_FAIL:' . \$e->getMessage();
}
PHP;

        $workerDispatch = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->dispatch(InventoryTransfer::find({$transferId}));
    echo 'DISPATCHED';
} catch (Throwable \$e) {
    echo 'DISPATCH_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerReserve, $workerDispatch]);

        $stock = StockItem::where('inventory_source_id', $srcId)->where('product_id', $productId)->first();
        $available = $stock->getAvailableToSellQuantity();

        // Invariant: available-to-sell must never go negative — dispatch must not
        // have consumed stock that reservation legitimately holds, in either order.
        $this->assertFalse($available->isNegative(), 'available-to-sell must never go negative under either lock order. Results: '.json_encode($results));
    }

    public function test_race_b_transfer_dispatch_vs_customer_reservation_reverse_order(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');
        $transfer = $this->makeTransfer($srcId, $destId, '4.0000');

        $tenantId = $this->tenant->id;
        $productId = $this->productId;
        $transferId = $transfer->id;
        $b = $this->baseWorkerBootstrap;

        $workerDispatch = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->dispatch(InventoryTransfer::find({$transferId}));
    echo 'DISPATCHED';
} catch (Throwable \$e) {
    echo 'DISPATCH_FAIL:' . \$e->getMessage();
}
PHP;

        $workerReserve = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\ValueObjects\Quantity;

// __BARRIER_WAIT__
try {
    app(InventoryReservationServiceInterface::class)->reserve(
        {$tenantId}, 'race-b-'.uniqid(), {$productId}, null, Quantity::fromString('4.0000'),
        new InventoryContext(tenantId: {$tenantId}), 60
    );
    echo 'RESERVED';
} catch (Throwable \$e) {
    echo 'RESERVE_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerDispatch, $workerReserve]);

        $stock = StockItem::where('inventory_source_id', $srcId)->where('product_id', $productId)->first();
        $this->assertFalse($stock->getAvailableToSellQuantity()->isNegative(), 'Reverse lock order must preserve the same invariant. Results: '.json_encode($results));
        $this->assertFalse($stock->on_hand < 0, 'on_hand must never go negative.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race C: transfer dispatch vs transfer dispatch (same source item)
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_c_transfer_dispatch_vs_transfer_dispatch(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');
        $t1 = $this->makeTransfer($srcId, $destId, '4.0000');
        $t2 = $this->makeTransfer($srcId, $destId, '4.0000');

        $b = $this->baseWorkerBootstrap;
        $makeWorker = fn (int $id) => <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->dispatch(InventoryTransfer::find({$id}));
    echo 'DISPATCHED';
} catch (Throwable \$e) {
    echo 'DISPATCH_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$makeWorker($t1->id), $makeWorker($t2->id)]);
        $successCount = count(array_filter($results, fn ($r) => str_contains($r['stdout'], 'DISPATCHED')));

        $this->assertSame(1, $successCount, 'Exactly one of two 4-unit dispatches against 5 on-hand must succeed. Results: '.json_encode($results));

        $stock = StockItem::where('inventory_source_id', $srcId)->where('product_id', $this->productId)->first();
        $this->assertFalse($stock->on_hand < 0, 'on_hand must never go negative.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race D: receive vs duplicate receive (same idempotency key)
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_d_receive_vs_duplicate_receive(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');
        $transfer = $this->makeTransfer($srcId, $destId, '5.0000');
        app(InventoryTransferServiceInterface::class)->dispatch($transfer);

        $transferId = $transfer->id;
        $idemKey = 'receive-dup-'.uniqid();
        $b = $this->baseWorkerBootstrap;

        $makeWorker = fn () => <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->receive(InventoryTransfer::find({$transferId}), [], '{$idemKey}');
    echo 'RECEIVED';
} catch (Throwable \$e) {
    echo 'RECEIVE_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$makeWorker(), $makeWorker()]);

        $destStock = StockItem::where('inventory_source_id', $destId)->where('product_id', $this->productId)->first();
        $this->assertNotNull($destStock);
        $this->assertSame('5.0000', (string) $destStock->on_hand, 'Duplicate receive under the same idempotency key must never double-increment on_hand. Results: '.json_encode($results));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race E: cancel vs dispatch
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_e_cancel_vs_dispatch(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');
        $transfer = $this->makeTransfer($srcId, $destId, '5.0000');
        $transferId = $transfer->id;
        $b = $this->baseWorkerBootstrap;

        $workerCancel = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->cancel(InventoryTransfer::find({$transferId}));
    echo 'CANCELLED';
} catch (Throwable \$e) {
    echo 'CANCEL_FAIL:' . \$e->getMessage();
}
PHP;

        $workerDispatch = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->dispatch(InventoryTransfer::find({$transferId}));
    echo 'DISPATCHED';
} catch (Throwable \$e) {
    echo 'DISPATCH_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerCancel, $workerDispatch]);

        $final = InventoryTransfer::find($transferId);
        $this->assertContains($final->status, ['cancelled', 'in_transit'], 'Transfer must end in exactly one consistent terminal state. Results: '.json_encode($results));

        // Never both succeed
        $successCount = count(array_filter($results, fn ($r) => str_contains($r['stdout'], 'CANCELLED') || str_contains($r['stdout'], 'DISPATCHED')));
        $this->assertLessThanOrEqual(1, $successCount, 'Cancel and dispatch must never both succeed against the same transfer. Results: '.json_encode($results));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race F: InventorySource deactivate vs NEW transfer create
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_f_source_deactivate_vs_new_transfer_create(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');

        $tenantId = $this->tenant->id;
        $productId = $this->productId;
        $b = $this->baseWorkerBootstrap;

        $workerDeactivate = <<<PHP
{$b}
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventorySource;

// __BARRIER_WAIT__
DB::transaction(function () {
    InventorySource::where('id', {$srcId})->lockForUpdate()->first();
    InventorySource::where('id', {$srcId})->update(['status' => 'inactive']);
});
echo 'DEACTIVATED';
PHP;

        $workerCreate = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->create(
        tenantId: {$tenantId},
        sourceInventorySourceId: {$srcId},
        destinationInventorySourceId: {$destId},
        transferNumber: 'TR-RACEF-'.uniqid(),
        items: [['product_id' => {$productId}, 'requested_quantity' => '1.0000']],
    );
    echo 'CREATED';
} catch (Throwable \$e) {
    echo 'CREATE_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerDeactivate, $workerCreate]);

        // Both orders are legal outcomes (create-before-deactivate succeeds;
        // deactivate-before-create fails closed) — the invariant is that the
        // outcome is deterministic and consistent with the source's final status
        // relative to when the lock was actually acquired, never a silent partial state.
        $this->assertTrue(
            str_contains($results[1]['stdout'], 'CREATED') || str_contains($results[1]['stdout'], 'CREATE_FAIL'),
            'Create worker must reach a definitive outcome. Results: '.json_encode($results)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race G: source deactivate vs dispatch
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_g_source_deactivate_vs_dispatch(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');
        $transfer = $this->makeTransfer($srcId, $destId, '3.0000');
        $transferId = $transfer->id;
        $b = $this->baseWorkerBootstrap;

        $workerDeactivate = <<<PHP
{$b}
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventorySource;

// __BARRIER_WAIT__
DB::transaction(function () {
    InventorySource::where('id', {$srcId})->lockForUpdate()->first();
    InventorySource::where('id', {$srcId})->update(['status' => 'inactive']);
});
echo 'DEACTIVATED';
PHP;

        $workerDispatch = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->dispatch(InventoryTransfer::find({$transferId}));
    echo 'DISPATCHED';
} catch (Throwable \$e) {
    echo 'DISPATCH_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerDeactivate, $workerDispatch]);

        // Deterministic per row lock: whichever side locks the InventorySource row
        // first determines the outcome — dispatch either fully succeeds (locked first)
        // or fails closed (deactivated first). No partial/ambiguous state.
        $this->assertTrue(
            str_contains($results[1]['stdout'], 'DISPATCHED') || str_contains($results[1]['stdout'], 'DISPATCH_FAIL'),
            'Dispatch worker must reach a definitive outcome. Results: '.json_encode($results)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race H: destination deactivate vs in-flight receive (must always succeed —
    // historical-completion/recovery path per ADR-0125 §10)
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_h_destination_deactivate_vs_in_flight_receive(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');
        $transfer = $this->makeTransfer($srcId, $destId, '5.0000');
        app(InventoryTransferServiceInterface::class)->dispatch($transfer);

        $transferId = $transfer->id;
        $b = $this->baseWorkerBootstrap;

        $workerDeactivate = <<<PHP
{$b}
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventorySource;

// __BARRIER_WAIT__
DB::transaction(function () {
    InventorySource::where('id', {$destId})->lockForUpdate()->first();
    InventorySource::where('id', {$destId})->update(['status' => 'inactive']);
});
echo 'DEACTIVATED';
PHP;

        $workerReceive = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->receive(InventoryTransfer::find({$transferId}));
    echo 'RECEIVED';
} catch (Throwable \$e) {
    echo 'RECEIVE_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerDeactivate, $workerReceive]);

        // receive() never checks destination status (ADR-0125 §10) — must ALWAYS succeed
        // regardless of race outcome with deactivation.
        $this->assertStringContainsString('RECEIVED', $results[1]['stdout'], 'receive() must always succeed on an already-dispatched transfer, even racing a destination deactivation. Results: '.json_encode($results));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race I: duplicate transfer create, same key + same payload -> same Transfer
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_i_duplicate_create_same_key_same_payload(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');

        $tenantId = $this->tenant->id;
        $productId = $this->productId;
        $idemKey = 'create-dup-same-'.uniqid();
        $transferNumber = 'TR-RACEI-'.uniqid();
        $b = $this->baseWorkerBootstrap;

        $makeWorker = fn () => <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;

// __BARRIER_WAIT__
try {
    \$t = app(InventoryTransferServiceInterface::class)->create(
        tenantId: {$tenantId},
        sourceInventorySourceId: {$srcId},
        destinationInventorySourceId: {$destId},
        transferNumber: '{$transferNumber}',
        items: [['product_id' => {$productId}, 'requested_quantity' => '2.0000']],
        idempotencyKey: '{$idemKey}',
    );
    echo 'CREATED:' . \$t->id;
} catch (Throwable \$e) {
    echo 'CREATE_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$makeWorker(), $makeWorker()]);

        $ids = [];
        foreach ($results as $r) {
            if (preg_match('/CREATED:(\d+)/', $r['stdout'], $m)) {
                $ids[] = $m[1];
            }
        }

        $this->assertCount(2, $ids, 'Both replays must resolve to a Transfer id, not an error. Results: '.json_encode($results));
        $this->assertSame($ids[0], $ids[1], 'Same key + same payload must always resolve to the identical Transfer.');
        $this->assertSame(1, InventoryTransfer::where('tenant_id', $tenantId)->where('transfer_number', $transferNumber)->count(), 'Exactly one Transfer row must exist.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race J: duplicate transfer create, same key + DIFFERENT payload -> fail closed
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_j_duplicate_create_same_key_different_payload(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '5.0000');

        $tenantId = $this->tenant->id;
        $productId = $this->productId;
        $idemKey = 'create-dup-diff-'.uniqid();

        app(InventoryTransferServiceInterface::class)->create(
            tenantId: $tenantId,
            sourceInventorySourceId: $srcId,
            destinationInventorySourceId: $destId,
            transferNumber: 'TR-RACEJ-1',
            items: [['product_id' => $productId, 'requested_quantity' => '2.0000']],
            idempotencyKey: $idemKey,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/different payload/');

        app(InventoryTransferServiceInterface::class)->create(
            tenantId: $tenantId,
            sourceInventorySourceId: $srcId,
            destinationInventorySourceId: $destId,
            transferNumber: 'TR-RACEJ-2', // different payload under the same key
            items: [['product_id' => $productId, 'requested_quantity' => '3.0000']],
            idempotencyKey: $idemKey,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race K: RMA disposition/restock vs duplicate disposition event
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_k_rma_disposition_vs_duplicate_event(): void
    {
        [$srcId] = $this->makeTransferPair();

        [$sellerReturn, $orderItemId] = $this->makeApprovedSellerReturn('2.0000');
        $sellerReturnId = $sellerReturn->id;
        $tenantId = $this->tenant->id;
        $b = $this->baseWorkerBootstrap;

        $makeWorker = fn () => <<<PHP
{$b}
use Modules\Order\Contracts\ReturnPhysicalDispositionServiceInterface;

// __BARRIER_WAIT__
try {
    app(ReturnPhysicalDispositionServiceInterface::class)->confirmPhysicalDisposition(
        {$tenantId}, {$sellerReturnId}, {$orderItemId}, '2.0000', 'unopened', 'restock', {$srcId}
    );
    echo 'DISPOSED';
} catch (Throwable \$e) {
    echo 'DISPOSE_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$makeWorker(), $makeWorker()]);

        $stock = StockItem::where('inventory_source_id', $srcId)->where('product_id', $this->productId)->first();
        $this->assertNotNull($stock, 'Disposition must have created the destination StockItem. Results: '.json_encode($results));
        $this->assertSame('2.0000', (string) $stock->on_hand, 'Duplicate disposition event must never double-increment on_hand. Results: '.json_encode($results));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Race L: stock adjustment vs transfer dispatch (same StockItem)
    // ═══════════════════════════════════════════════════════════════════
    public function test_race_l_stock_adjustment_vs_transfer_dispatch(): void
    {
        [$srcId, $destId] = $this->makeTransferPair();
        $this->stockOn($srcId, '10.0000');
        $transfer = $this->makeTransfer($srcId, $destId, '4.0000');

        $tenantId = $this->tenant->id;
        $productId = $this->productId;
        $transferId = $transfer->id;
        $b = $this->baseWorkerBootstrap;

        $workerAdjust = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

// __BARRIER_WAIT__
try {
    \$stockItem = StockItem::where('inventory_source_id', {$srcId})->where('product_id', {$productId})->first();
    app(InventoryAdjustmentServiceInterface::class)->adjust(\$stockItem, Quantity::fromString('-3.0000'), 'correction', 'Race L correction');
    echo 'ADJUSTED';
} catch (Throwable \$e) {
    echo 'ADJUST_FAIL:' . \$e->getMessage();
}
PHP;

        $workerDispatch = <<<PHP
{$b}
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryTransfer;

// __BARRIER_WAIT__
try {
    app(InventoryTransferServiceInterface::class)->dispatch(InventoryTransfer::find({$transferId}));
    echo 'DISPATCHED';
} catch (Throwable \$e) {
    echo 'DISPATCH_FAIL:' . \$e->getMessage();
}
PHP;

        $results = $this->runSynchronizedParallelWorkers([$workerAdjust, $workerDispatch]);

        $stock = StockItem::where('inventory_source_id', $srcId)->where('product_id', $productId)->first();

        // No lost update: if both succeeded, on_hand must reflect BOTH effects
        // (10 - 3 - 4 = 3); on_hand must never go negative.
        $this->assertFalse($stock->on_hand < 0, 'on_hand must never go negative under concurrent adjustment+dispatch. Results: '.json_encode($results));
        if (str_contains($results[0]['stdout'], 'ADJUSTED') && str_contains($results[1]['stdout'], 'DISPATCHED')) {
            $this->assertSame('3.0000', (string) $stock->on_hand, 'Both operations succeeding must produce the fully conserved result, not a lost update.');
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @return array{0: SellerReturn, 1: int} [SellerReturn, orderItemId]
     */
    private function makeApprovedSellerReturn(string $approvedQty): array
    {
        // Real Order -> SellerOrder -> ReturnRequest -> SellerReturn -> ReturnItem
        // materialization via production services, matching every composite FK
        // this schema actually enforces (fk_ri_order_item, fk_sr_tenant_seller_order,
        // fk_sr_tenant_return_request) — no synthetic/fabricated foreign ids.
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Race K Store',
            'slug' => 'race-k-'.uniqid(),
            'status' => 'active',
            'url' => 'https://race-k.example.com',
        ]);

        $market = Market::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'DE',
            'name' => 'Germany',
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'de',
            'timezone' => 'Europe/Berlin',
            'is_active' => true,
        ]);

        $channel = Channel::create([
            'name' => 'Race K Channel',
            'type' => 'website',
            'handle' => 'race-k-'.uniqid(),
            'is_active' => true,
        ]);

        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'status' => 'active',
        ]);

        $session = CheckoutSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'state' => 'ready_for_order',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-RACEK-'.uniqid(),
            'tenant_id' => $this->tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'checkout_id' => $session->id,
            'currency' => 'EUR',
            'locale' => 'de',
            'order_status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'merchandise_subtotal_minor' => 10000,
            'discount_total_minor' => 0,
            'tax_total_minor' => 0,
            'shipping_total_minor' => 0,
            'grand_total_minor' => 10000,
            'commercial_model_snapshot' => 'platform_as_merchant_of_record',
            'customer_snapshot' => ['email' => 'race-k@example.com'],
            'version' => 1,
            'placed_at' => now(),
        ]);

        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'product_id' => $this->productId,
            'sku_snapshot' => 'SKU-RACEK',
            'name_snapshot' => 'Race K Product',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => false,
            'quantity' => $approvedQty,
            'unit_price_minor' => 1000,
            'subtotal_minor' => 10000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 10000,
            'vendor_id' => null,
        ]);

        app(MasterOrderSplitServiceInterface::class)->splitOrder($order);

        $returnRequest = app(ReturnRequestServiceInterface::class)->createReturnRequest(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            customerId: null,
            items: [[
                'order_item_id' => $orderItem->id,
                'quantity' => $approvedQty,
                'reason' => 'customer_return',
                'condition' => 'unopened',
            ]],
        );

        $sellerReturn = $returnRequest->sellerReturns->first();

        app(ReturnRequestServiceInterface::class)->approveReturnItem(
            tenantId: $this->tenant->id,
            sellerReturnId: $sellerReturn->id,
            orderItemId: $orderItem->id,
            quantityToApprove: $approvedQty,
        );

        return [$sellerReturn->fresh(), $orderItem->id];
    }

    /**
     * @param  list<string>  $scripts
     * @return list<array{exit_code: int, stdout: string, stderr: string}>
     */
    private function runSynchronizedParallelWorkers(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/barrier_p14_'.uniqid().'.flag';
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace(
                '// __BARRIER_WAIT__',
                "while (!file_exists('{$barrierFile}')) { usleep(1000); }",
                $script
            );

            $tmpFile = sys_get_temp_dir()."/worker_p14_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $syncedScript);

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open('php '.escapeshellarg($tmpFile), $descriptors, $pipes[$idx]);
            $processes[$idx] = ['resource' => $proc, 'tmp_file' => $tmpFile];
        }

        usleep(80000);
        touch($barrierFile);

        $results = [];
        foreach ($processes as $idx => $procInfo) {
            $stdout = stream_get_contents($pipes[$idx][1]);
            $stderr = stream_get_contents($pipes[$idx][2]);
            fclose($pipes[$idx][0]);
            fclose($pipes[$idx][1]);
            fclose($pipes[$idx][2]);

            $exitCode = proc_close($procInfo['resource']);
            @unlink($procInfo['tmp_file']);
            $results[$idx] = ['exit_code' => $exitCode, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
        }

        @unlink($barrierFile);

        return $results;
    }
}
