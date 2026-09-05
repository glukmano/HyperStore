<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\B2B\Enums\CompanyStatus;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\CompanyCreditAccount;
use Modules\B2B\Services\CompanyCreditService;
use Tests\TestCase;

/**
 * Owner Delta §1/§3: two simultaneous terms-Orders must not both reserve
 * credit exposure that together exceeds the Company's approved limit — the
 * CompanyCreditAccountLock row-lock must actually serialize concurrent
 * reservation attempts.
 */
class PostgreSqlCompanyCreditConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private Company $company;

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
            $this->markTestSkipped('PostgreSqlCompanyCreditConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Credit Concurrency Tenant', 'slug' => 'credit-cc-'.uniqid()]);
        $this->company = Company::create(['tenant_id' => $this->tenant->id, 'name' => 'Acme', 'status' => CompanyStatus::Active]);
        CompanyCreditAccount::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'currency' => 'USD',
            'approved_limit_minor' => 100000,
        ]);
    }

    /**
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/b2b_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_b2b_{$idx}_".uniqid().'.php';
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

    public function test_two_concurrent_reservations_exceeding_the_limit_do_not_both_succeed(): void
    {
        $bootstrap = $this->getBootstrapScript();
        $companyId = $this->company->id;

        $workerCode = function (string $orderUuid) use ($bootstrap, $companyId): string {
            return "{$bootstrap}
\$company = \\Modules\\B2B\\Models\\Company::find({$companyId});
// __BARRIER_WAIT__
try {
    app(\\Modules\\B2B\\Services\\CompanyCreditService::class)->reserveForOrder(\$company, 70000, 'USD', '{$orderUuid}');
    echo 'SUCCESS';
    exit(0);
} catch (\\Modules\\B2B\\Exceptions\\InsufficientCompanyCreditException \$e) {
    echo 'REJECTED';
    exit(1);
}
";
        };

        $results = $this->executeConcurrently([
            $workerCode('order-race-a'),
            $workerCode('order-race-b'),
        ]);

        $successCount = 0;
        $rejectedCount = 0;
        foreach ($results as $r) {
            if (str_contains($r['stdout'], 'SUCCESS')) {
                $successCount++;
            } elseif (str_contains($r['stdout'], 'REJECTED')) {
                $rejectedCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one of the two 70000-minor reservations (against a 100000 limit) must succeed.');
        $this->assertSame(1, $rejectedCount);

        $available = (new CompanyCreditService)->getAvailableCreditMinor($this->company->fresh(), 'USD');
        $this->assertSame(30000, $available);
    }
}
