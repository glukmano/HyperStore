<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\CustomerProfile;
use Modules\Promotions\Models\LoyaltyProgram;
use Modules\Promotions\Models\LoyaltyProgramCurrencyRule;
use Modules\Promotions\Services\LoyaltyService;
use Tests\TestCase;

/**
 * Owner Delta correction §12: proves the loyalty_account_locks row-lock
 * actually serializes concurrent redemption against the SAME balance — two
 * real OS processes racing to redeem more than the available balance
 * combined must not both succeed.
 */
class PostgreSqlLoyaltyRedemptionConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private CustomerProfile $profile;

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
            $this->markTestSkipped('PostgreSqlLoyaltyRedemptionConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Loyalty Concurrency Tenant', 'slug' => 'loy-cc-'.uniqid()]);
        $user = User::factory()->create();
        $this->profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);

        $program = LoyaltyProgram::create(['tenant_id' => $this->tenant->id, 'name' => 'P', 'is_active' => true]);
        LoyaltyProgramCurrencyRule::create([
            'tenant_id' => $this->tenant->id,
            'loyalty_program_id' => $program->id,
            'currency' => 'EUR',
            'minor_units_per_point' => 100,
            'point_redemption_value_minor' => 5,
            'is_active' => true,
        ]);

        app(LoyaltyService::class)->earnPoints($this->profile, 100, 'test', 'seed-100');
    }

    /**
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/loy_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_loy_{$idx}_".uniqid().'.php';
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

    public function test_two_concurrent_redemptions_exceeding_balance_do_not_both_succeed(): void
    {
        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenant->id;
        $profileId = $this->profile->id;

        $workerCode = function (string $sourceUuid) use ($bootstrap, $profileId): string {
            return "{$bootstrap}
\$profile = \\Modules\\Customers\\Models\\CustomerProfile::find({$profileId});
// __BARRIER_WAIT__
try {
    \$value = app(\\Modules\\Promotions\\Services\\LoyaltyService::class)->redeemPoints(\$profile, 80, 'EUR', '{$sourceUuid}');
    echo 'SUCCESS:' . \$value;
    exit(0);
} catch (\\Modules\\Promotions\\Exceptions\\InsufficientLoyaltyPointsException \$e) {
    echo 'REJECTED';
    exit(1);
}
";
        };

        $scripts = [$workerCode('redeem-race-a'), $workerCode('redeem-race-b')];
        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $rejectedCount = 0;
        foreach ($results as $r) {
            if (str_contains($r['stdout'], 'SUCCESS')) {
                $successCount++;
            } elseif (str_contains($r['stdout'], 'REJECTED')) {
                $rejectedCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one of the two 80-point redemptions (against a 100-point balance) must succeed.');
        $this->assertSame(1, $rejectedCount);

        $finalBalance = app(LoyaltyService::class)->getAvailableBalance($this->profile->fresh());
        $this->assertSame(20, $finalBalance);
    }
}
