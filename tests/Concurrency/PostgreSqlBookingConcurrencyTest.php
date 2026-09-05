<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Booking\Models\BookingResource;
use Modules\Booking\Models\BookingService;
use Modules\Booking\Models\BookingSlot;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\CustomerProfile;
use Tests\TestCase;

/**
 * Owner Delta §5/§6/§8: for capacity=1, two concurrent hold attempts for
 * the SAME slot must have exactly one succeed. For capacity=3, five
 * concurrent attempts must yield exactly 3 successes — used capacity
 * (a live COUNT(*), never a cached counter) must never exceed capacity.
 */
class PostgreSqlBookingConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'hyperstore',
            'database.connections.pgsql.username' => 'lukman',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 5432,
            'database.connections.pgsql.timezone' => 'UTC',
        ]);
        DB::purge('pgsql');

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSqlBookingConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Booking Concurrency Tenant', 'slug' => 'book-cc-'.uniqid()]);
    }

    private function makeSlot(int $capacity): BookingSlot
    {
        $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'BOOK-CC-'.uniqid(), 'name' => 'Service', 'slug' => 'book-cc-'.uniqid(), 'product_type' => 'booking', 'status' => 'active']);
        $bookingService = BookingService::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'duration_minutes' => 60, 'timezone' => 'UTC', 'buffer_minutes' => 0]);
        $resource = BookingResource::create(['tenant_id' => $this->tenant->id, 'name' => 'Room', 'capacity' => $capacity]);
        $resource->eligibleServices()->attach($bookingService->id);

        return BookingSlot::create([
            'tenant_id' => $this->tenant->id,
            'booking_resource_id' => $resource->id,
            'booking_service_id' => $bookingService->id,
            'starts_at' => CarbonImmutable::now()->addDay(),
            'ends_at' => CarbonImmutable::now()->addDay()->addHour(),
            'capacity' => $capacity,
        ]);
    }

    /**
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/book_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_book_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $syncedScript);

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open('php '.escapeshellarg($tmpFile), $descriptors, $pipes[$idx]);
            $processes[$idx] = ['resource' => $proc, 'tmp_file' => $tmpFile];
        }

        usleep(50000);
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
            $results[$idx] = ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
        }

        @unlink($barrierFile);

        return $results;
    }

    private function getBootstrapScript(): string
    {
        $basePath = addslashes(base_path());

        return "<?php
require '{$basePath}/vendor/autoload.php';
\$app = require_once '{$basePath}/bootstrap/app.php';
\$kernel = \$app->make(Illuminate\\Contracts\\Console\\Kernel::class);
\$kernel->bootstrap();

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.database' => 'hyperstore',
    'database.connections.pgsql.username' => 'lukman',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 5432,
    'database.connections.pgsql.timezone' => 'UTC',
]);
Illuminate\\Support\\Facades\\DB::purge('pgsql');
";
    }

    /**
     * @return string[]
     */
    private function makeProfiles(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create();
            $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);
            $ids[] = $profile->id;
        }

        return $ids;
    }

    private function workerScript(string $bootstrap, int $profileId, int $slotId, int $serviceId, string $checkoutUuid): string
    {
        return "{$bootstrap}
\$profile = \\Modules\\Customers\\Models\\CustomerProfile::find({$profileId});
\$slot = \\Modules\\Booking\\Models\\BookingSlot::find({$slotId});
// __BARRIER_WAIT__
try {
    \$booking = app(\\Modules\\Booking\\Services\\BookingHoldService::class)->hold(\$slot, \$profile, {$serviceId}, '{$checkoutUuid}', (string) now()->addMinutes(15));
    echo 'SUCCESS:' . \$booking->id;
    exit(0);
} catch (\\Modules\\Booking\\Exceptions\\SlotCapacityExceededException \$e) {
    echo 'REJECTED';
    exit(1);
}
";
    }

    public function test_capacity_one_slot_two_concurrent_holds_exactly_one_succeeds(): void
    {
        $slot = $this->makeSlot(1);
        [$p1, $p2] = $this->makeProfiles(2);
        $bootstrap = $this->getBootstrapScript();

        $results = $this->executeConcurrently([
            $this->workerScript($bootstrap, $p1, $slot->id, $slot->booking_service_id, (string) Str::uuid()),
            $this->workerScript($bootstrap, $p2, $slot->id, $slot->booking_service_id, (string) Str::uuid()),
        ]);

        $successCount = 0;
        foreach ($results as $r) {
            if (str_contains($r['stdout'], 'SUCCESS')) {
                $successCount++;
            }
        }

        $this->assertSame(1, $successCount);
        $this->assertSame(1, $slot->fresh()->usedCapacity());
    }

    public function test_capacity_three_slot_five_concurrent_holds_exactly_three_succeed(): void
    {
        $slot = $this->makeSlot(3);
        $profileIds = $this->makeProfiles(5);
        $bootstrap = $this->getBootstrapScript();

        $scripts = [];
        foreach ($profileIds as $profileId) {
            $scripts[] = $this->workerScript($bootstrap, $profileId, $slot->id, $slot->booking_service_id, (string) Str::uuid());
        }

        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        foreach ($results as $r) {
            if (str_contains($r['stdout'], 'SUCCESS')) {
                $successCount++;
            }
        }

        $this->assertSame(3, $successCount, 'Exactly 3 of 5 concurrent holds against a capacity-3 slot must succeed.');
        $this->assertSame(3, $slot->fresh()->usedCapacity());
    }
}
