<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\CustomerProfile;
use Modules\DigitalDelivery\Enums\EntitlementType;
use Modules\DigitalDelivery\Models\CustomerEntitlement;
use Tests\TestCase;

/**
 * Owner Delta §13/D.56: two simultaneous download requests against an
 * entitlement with exactly one remaining use — exactly one succeeds. The
 * reservation (lock -> check -> increment -> commit) must happen BEFORE
 * any byte streams, never "check -> stream -> increment later."
 */
class PostgreSqlDigitalDownloadConcurrencyTest extends TestCase
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
            $this->markTestSkipped('PostgreSqlDigitalDownloadConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Digital Download Concurrency Tenant', 'slug' => 'dd-cc-'.uniqid()]);
    }

    /**
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/dd_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_dd_{$idx}_".uniqid().'.php';
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

    public function test_two_concurrent_downloads_against_an_entitlement_with_one_remaining_use_exactly_one_succeeds(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);

        $entitlement = CustomerEntitlement::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => $profile->id,
            'entitlement_type' => EntitlementType::DigitalDownload,
            'source_type' => 'order_item',
            'source_uuid' => 'oi-cc-1',
            'granted_at' => now(),
            'max_uses' => 1,
            'used_count' => 0,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;
        $entitlementId = $entitlement->id;

        $workerCode = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\DigitalDelivery\\Services\\DigitalAssetDeliveryService::class);
    \$reserved = \$service->reserveDownload({$tenantId}, {$entitlementId}, 'iphash', 'agent');
    echo 'SUCCESS:' . \$reserved->used_count;
    exit(0);
} catch (\\Modules\\DigitalDelivery\\Exceptions\\DownloadLimitExceededException \$e) {
    echo 'REJECTED';
    exit(1);
}
";

        $results = $this->executeConcurrently([$workerCode, $workerCode]);

        $successCount = 0;
        $rejectedCount = 0;
        foreach ($results as $r) {
            if (str_contains($r['stdout'], 'SUCCESS')) {
                $successCount++;
            } elseif (str_contains($r['stdout'], 'REJECTED')) {
                $rejectedCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one of two simultaneous downloads against a one-remaining-use entitlement must succeed.');
        $this->assertSame(1, $rejectedCount);

        $entitlement->refresh();
        $this->assertSame(1, $entitlement->used_count);
    }
}
