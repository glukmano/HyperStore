<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Models\StoreValueAccount;
use Modules\Wallet\Services\StoreValueService;
use Tests\TestCase;

/**
 * Owner Delta §16/D.56: Store Value cannot double-spend under real
 * PostgreSQL concurrency — Wallet, Store Credit, and Gift Card all share
 * the identical StoreValueService::placeHold() lock-anchor mechanism, so
 * one representative race per instrument type proves all three.
 */
class PostgreSqlStoreValueConcurrencyTest extends TestCase
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
            $this->markTestSkipped('PostgreSqlStoreValueConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Store Value Concurrency Tenant', 'slug' => 'sv-cc-'.uniqid()]);
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);
    }

    private function makeAccount(StoreValueInstrumentType $type): StoreValueAccount
    {
        return app(StoreValueService::class)->findOrCreateAccount($this->tenant->id, null, $type, 'USD');
    }

    private function seedBalance(StoreValueAccount $account, int $amountMinor, string $sourceUuid): void
    {
        app(StoreValueService::class)->issue($account, $amountMinor, 'manual_adjustment', $sourceUuid);
    }

    /**
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/sv_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);
            $tmpFile = sys_get_temp_dir()."/worker_sv_{$idx}_".uniqid().'.php';
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

    private function workerScript(string $bootstrap, int $accountId, int $amountMinor, string $sourceUuid): string
    {
        return "{$bootstrap}
\$account = \\Modules\\Wallet\\Models\\StoreValueAccount::find({$accountId});
// __BARRIER_WAIT__
try {
    \$hold = app(\\Modules\\Wallet\\Services\\StoreValueService::class)->placeHold(\$account, {$amountMinor}, '{$sourceUuid}');
    echo 'SUCCESS:' . \$hold->id;
    exit(0);
} catch (\\Modules\\Wallet\\Exceptions\\InsufficientStoreValueBalanceException \$e) {
    echo 'REJECTED';
    exit(1);
}
";
    }

    private function runDoubleSpendRace(StoreValueInstrumentType $type): void
    {
        $account = $this->makeAccount($type);
        $this->seedBalance($account, 1000, "seed-{$type->value}");

        $bootstrap = $this->getBootstrapScript();

        $results = $this->executeConcurrently([
            $this->workerScript($bootstrap, (int) $account->id, 1000, "checkout-{$type->value}-a"),
            $this->workerScript($bootstrap, (int) $account->id, 1000, "checkout-{$type->value}-b"),
        ]);

        $successCount = 0;
        foreach ($results as $r) {
            if (str_contains($r['stdout'], 'SUCCESS')) {
                $successCount++;
            }
        }

        $this->assertSame(1, $successCount, "Exactly one of two concurrent holds against a {$type->value} account with balance for only one must succeed.");
        $this->assertSame(0, app(StoreValueService::class)->getAvailableBalanceMinor($account->fresh()));
    }

    public function test_wallet_cannot_double_spend_under_concurrent_holds(): void
    {
        $this->runDoubleSpendRace(StoreValueInstrumentType::Wallet);
    }

    public function test_store_credit_cannot_double_spend_under_concurrent_holds(): void
    {
        $this->runDoubleSpendRace(StoreValueInstrumentType::StoreCredit);
    }

    public function test_gift_card_cannot_double_spend_under_concurrent_holds(): void
    {
        $this->runDoubleSpendRace(StoreValueInstrumentType::GiftCard);
    }
}
