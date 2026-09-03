<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Stores\Contracts\StoreCreationServiceInterface;
use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Models\ImpersonationSession;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Enums\TenantOperationalStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSqlControlCenterConcurrencyTest extends TestCase
{
    private Tenant $tenant;

    private PlatformSaasPlan $plan;

    private User $adminUser;

    private User $targetUser;

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
        DB::reconnect('pgsql');

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Control Center concurrency tests require PostgreSQL.');
        }

        $this->plan = PlatformSaasPlan::create([
            'code' => 'cc-conc-plan-'.uniqid(),
            'name' => 'Concurrency Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 5],
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Concurrency Tenant '.uniqid(),
            'slug' => 'conc-tenant-'.uniqid(),
            'status' => 'active',
        ]);

        app(TenantLicenseServiceInterface::class)->assignLicense($this->tenant->id, $this->plan->id);

        $this->adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'adm_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => true,
        ]);

        $this->targetUser = User::create([
            'name' => 'Target User',
            'email' => 'tgt_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->targetUser->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_race_a_impersonation_revocation_vs_authorized_action(): void
    {
        $impersonationService = app(ImpersonationServiceInterface::class);
        $result = $impersonationService->startSession(
            impersonatorUserId: $this->adminUser->id,
            targetUserId: $this->targetUser->id,
            tenantId: $this->tenant->id,
            storeId: null,
            vendorId: null,
            reason: 'Concurrent inspection',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $session = $result['session'];
        $token = $result['token'];
        $bootstrap = $this->getBootstrapScript();
        $sessionUuid = $session->uuid;

        // Worker 1: Atomic executeAuthorized inside production service boundary
        $worker1 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$token = '{$token}';
    \$svc = app(\\App\\Core\\SuperAdmin\\Contracts\\ImpersonationServiceInterface::class);
    \$output = \$svc->executeAuthorized(\$token, 'update_store_settings', function (\$authSession) {
        usleep(50000);
        return 'ACTION_SUCCESS:' . \$authSession->id;
    });
    echo \$output;
    exit(0);
} catch (\\App\\Core\\SuperAdmin\\Exceptions\\ImpersonationRevokedException \$e) {
    echo 'ACTION_REVOKED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Revokes impersonation session under row lock
        $worker2 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$svc = app(\\App\\Core\\SuperAdmin\\Contracts\\ImpersonationServiceInterface::class);
    \$svc->revokeSession('{$sessionUuid}', 'Emergency admin revocation');
    echo 'REVOKE_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code'], 'Worker 1 error: '.($results[0]['stdout'] ?: $results[0]['stderr']));
        $this->assertSame(0, $results[1]['exit_code'], 'Worker 2 error: '.($results[1]['stdout'] ?: $results[1]['stderr']));

        $this->assertSame('REVOKE_SUCCESS', $results[1]['stdout']);

        $outcomeA = str_starts_with($results[0]['stdout'], 'ACTION_SUCCESS:');
        $outcomeB = str_starts_with($results[0]['stdout'], 'ACTION_REVOKED:');

        $this->assertTrue($outcomeA || $outcomeB, 'Must resolve to either Action Success or Action Revoked.');

        $this->assertSame('revoked', $session->fresh()->status);
    }

    public function test_race_b_tenant_suspension_vs_tenant_resource_mutation(): void
    {
        $tenantId = $this->tenant->id;
        $bootstrap = $this->getBootstrapScript();

        // Worker 1: Super Admin suspends tenant via production service
        $worker1 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$svc = app(\\App\\Core\\SuperAdmin\\Contracts\\TenantLifecycleServiceInterface::class);
    \$svc->suspend({$tenantId}, 'Billing suspension');
    echo 'SUSPENSION_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Tenant Admin creates store calling ONLY the production StoreCreationService
        $worker2 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$svc = app(\\App\\Core\\Stores\\Contracts\\StoreCreationServiceInterface::class);
    \$store = \$svc->createStore({$tenantId}, ['name' => 'Store Conc', 'slug' => 'st-conc-' . uniqid()]);
    echo 'STORE_CREATED:' . \$store->id;
    exit(0);
} catch (\\App\\Core\\SuperAdmin\\Exceptions\\TenantSuspendedException \$e) {
    echo 'STORE_REJECTED_SUSPENDED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code'], 'Worker 1 error: '.($results[0]['stdout'] ?: $results[0]['stderr']));
        $this->assertSame(0, $results[1]['exit_code'], 'Worker 2 error: '.($results[1]['stdout'] ?: $results[1]['stderr']));

        $this->assertSame('SUSPENSION_SUCCESS', $results[0]['stdout']);

        $outcomeA = str_starts_with($results[1]['stdout'], 'STORE_CREATED:');
        $outcomeB = str_starts_with($results[1]['stdout'], 'STORE_REJECTED_SUSPENDED:');

        $this->assertTrue($outcomeA || $outcomeB, 'Must resolve to either Store Created or Store Rejected Suspended.');

        $this->assertSame(TenantOperationalStatus::Suspended, $this->tenant->fresh()->status);
    }

    public function test_race_c_staff_role_revocation_vs_administrative_operation(): void
    {
        $tenantId = $this->tenant->id;
        $userId = $this->targetUser->id;
        $bootstrap = $this->getBootstrapScript();

        // Worker 1: Owner revokes staff membership via production TenantMembershipService
        $worker1 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$svc = app(\\App\\Core\\SuperAdmin\\Contracts\\TenantMembershipServiceInterface::class);
    \$svc->revokeMembership({$tenantId}, {$userId});
    echo 'ROLE_REVOKED';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Admin creates store via production ContextualMutationAuthorizer
        $worker2 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$tenantId = {$tenantId};
    \$userId = {$userId};
    \$authorizer = app(\\App\\Core\\SuperAdmin\\Contracts\\ContextualMutationAuthorizerInterface::class);
    \$storeService = app(\\App\\Core\\Stores\\Contracts\\StoreCreationServiceInterface::class);
    \$store = \$authorizer->executeTenantAuthorized({$tenantId}, {$userId}, 'admin', function () use (\$storeService, \$tenantId) {
        return \$storeService->createStore({$tenantId}, ['name' => 'Store Staff Conc', 'slug' => 'st-staff-' . uniqid()]);
    });
    echo 'OPERATION_COMMITTED:' . \$store->id;
    exit(0);
} catch (\\App\\Core\\SuperAdmin\\Exceptions\\UnauthorizedContextException \$e) {
    echo 'OPERATION_BLOCKED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code'], 'Worker 1 error: '.($results[0]['stdout'] ?: $results[0]['stderr']));
        $this->assertSame(0, $results[1]['exit_code'], 'Worker 2 error: '.($results[1]['stdout'] ?: $results[1]['stderr']));

        $this->assertSame('ROLE_REVOKED', $results[0]['stdout']);

        $outcomeA = str_starts_with($results[1]['stdout'], 'OPERATION_COMMITTED:');
        $outcomeB = str_starts_with($results[1]['stdout'], 'OPERATION_BLOCKED:');

        $this->assertTrue($outcomeA || $outcomeB, 'Must resolve to either Operation Committed or Operation Blocked.');
    }

    public function test_race_d_saas_plan_hard_limit_reduction_vs_tenant_resource_creation(): void
    {
        $planId = $this->plan->id;
        $tenantId = $this->tenant->id;
        $bootstrap = $this->getBootstrapScript();

        // Create 4 initial stores under tenant (current limit = 5, usage = 4)
        $storeService = app(StoreCreationServiceInterface::class);
        for ($i = 1; $i <= 4; $i++) {
            $storeService->createStore($tenantId, ['name' => "Pre-Store {$i}", 'slug' => "pre-st-{$i}-".uniqid()]);
        }

        // Worker 1: Super Admin reduces hard limit from 5 to 4
        $worker1 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$svc = app(\\App\\Core\\SuperAdmin\\Contracts\\PlatformSaasPlanMutationServiceInterface::class);
    \$svc->updateHardLimits({$planId}, ['max_stores' => 4]);
    echo 'PLAN_REDUCTION_SUCCESS';
    exit(0);
} catch (\\App\\Core\\SuperAdmin\\Exceptions\\SaasPlanLimitReductionException \$e) {
    echo 'PLAN_REDUCTION_REJECTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Tenant Admin creates Store #5
        $worker2 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$svc = app(\\App\\Core\\Stores\\Contracts\\StoreCreationServiceInterface::class);
    \$store = \$svc->createStore({$tenantId}, ['name' => 'Store 5', 'slug' => 'st-5-' . uniqid()]);
    echo 'STORE_5_CREATED:' . \$store->id;
    exit(0);
} catch (\\App\\Core\\SuperAdmin\\Exceptions\\TenantResourceQuotaExceededException \$e) {
    echo 'STORE_5_QUOTA_REJECTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code'], 'Worker 1 error: '.($results[0]['stdout'] ?: $results[0]['stderr']));
        $this->assertSame(0, $results[1]['exit_code'], 'Worker 2 error: '.($results[1]['stdout'] ?: $results[1]['stderr']));

        $outcomeA = ($results[0]['stdout'] === 'PLAN_REDUCTION_SUCCESS' && str_starts_with($results[1]['stdout'], 'STORE_5_QUOTA_REJECTED:'));
        $outcomeB = (str_starts_with($results[0]['stdout'], 'PLAN_REDUCTION_REJECTED:') && str_starts_with($results[1]['stdout'], 'STORE_5_CREATED:'));

        $this->assertTrue(
            $outcomeA || $outcomeB,
            'Must serialize: Either Plan reduction commits first (Store 5 rejected) OR Store 5 commits first (Plan reduction rejected).'
        );

        $finalStoreCount = Store::where('tenant_id', $tenantId)->count();
        $finalLimit = (int) $this->plan->fresh()->limits['max_stores'];

        // FORBIDDEN INVARIANT: Limit committed to 4 while 5 stores exist in DB!
        $this->assertFalse(
            $finalLimit === 4 && $finalStoreCount === 5,
            'FORBIDDEN INVARIANT: SaaS plan limit was committed to 4 while committed usage is 5 stores.'
        );
    }

    public function test_race_e_concurrent_start_session_by_same_impersonator(): void
    {
        $impersonatorId = $this->adminUser->id;
        $targetId1 = $this->targetUser->id;

        $targetUser2 = User::create([
            'name' => 'Target User 2',
            'email' => 'tgt2_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        $bootstrap = $this->getBootstrapScript();

        // Worker 1: Attempts to start session targeting target 1
        $worker1 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$svc = app(\\App\\Core\\SuperAdmin\\Contracts\\ImpersonationServiceInterface::class);
    \$res = \$svc->startSession({$impersonatorId}, {$targetId1}, null, null, null, 'Worker 1 inspection', '127.0.0.1', 'Worker 1');
    echo 'START_SUCCESS:' . \$res['session']->id;
    exit(0);
} catch (\\App\\Core\\SuperAdmin\\Exceptions\\NestedImpersonationForbiddenException \$e) {
    echo 'START_REJECTED_NESTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Concurrent start session by same impersonator targeting target 2
        $targetId2 = $targetUser2->id;
        $worker2 = "{$bootstrap}
try {
    // __BARRIER_WAIT__
    \$svc = app(\\App\\Core\\SuperAdmin\\Contracts\\ImpersonationServiceInterface::class);
    \$res = \$svc->startSession({$impersonatorId}, {$targetId2}, null, null, null, 'Worker 2 inspection', '127.0.0.1', 'Worker 2');
    echo 'START_SUCCESS:' . \$res['session']->id;
    exit(0);
} catch (\\App\\Core\\SuperAdmin\\Exceptions\\NestedImpersonationForbiddenException \$e) {
    echo 'START_REJECTED_NESTED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code'], 'Worker 1 error: '.($results[0]['stdout'] ?: $results[0]['stderr']));
        $this->assertSame(0, $results[1]['exit_code'], 'Worker 2 error: '.($results[1]['stdout'] ?: $results[1]['stderr']));

        $outcome1 = str_starts_with($results[0]['stdout'], 'START_SUCCESS:');
        $outcome2 = str_starts_with($results[1]['stdout'], 'START_SUCCESS:');

        // Exactly one must succeed and exactly one must fail with NestedImpersonationForbiddenException
        $this->assertTrue(
            ($outcome1 && ! $outcome2 && str_starts_with($results[1]['stdout'], 'START_REJECTED_NESTED:')) ||
            ($outcome2 && ! $outcome1 && str_starts_with($results[0]['stdout'], 'START_REJECTED_NESTED:')),
            'Must serialize: exactly one startSession succeeds and the concurrent competitor is rejected.'
        );

        // Database partial unique index verification: exactly one active session exists
        $activeSessionsCount = ImpersonationSession::where('impersonator_user_id', $impersonatorId)
            ->where('status', 'active')
            ->count();

        $this->assertSame(1, $activeSessionsCount, 'PostgreSQL partial unique constraint holds: exactly one active session exists.');
    }

    /**
     * Helper to spawn synchronized concurrent worker scripts via proc_open with a file barrier.
     *
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/cc_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);

            $tmpFile = sys_get_temp_dir()."/worker_cc_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $syncedScript);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open("php {$tmpFile}", $descriptors, $pipes[$idx]);
            $processes[$idx] = [
                'process' => $process,
                'file' => $tmpFile,
            ];
        }

        usleep(15000);
        touch($barrierFile);

        $results = [];
        foreach ($processes as $idx => $procInfo) {
            $stdout = stream_get_contents($pipes[$idx][1]);
            $stderr = stream_get_contents($pipes[$idx][2]);

            fclose($pipes[$idx][0]);
            fclose($pipes[$idx][1]);
            fclose($pipes[$idx][2]);

            $exitCode = proc_close($procInfo['process']);
            @unlink($procInfo['file']);

            $results[$idx] = [
                'exit_code' => $exitCode,
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
            ];
        }

        @unlink($barrierFile);

        return $results;
    }

    private function getBootstrapScript(): string
    {
        $basePath = addslashes(base_path());

        return "<?php
putenv('DB_CONNECTION=pgsql');
putenv('DB_DATABASE=hyperstore');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=5432');
putenv('DB_USERNAME=lukman');
putenv('DB_PASSWORD=');

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

\\Illuminate\\Support\\Facades\\DB::purge('pgsql');
\\Illuminate\\Support\\Facades\\DB::purge('sqlite');
\\Illuminate\\Support\\Facades\\DB::setDefaultConnection('pgsql');
\\Illuminate\\Support\\Facades\\DB::reconnect('pgsql');
";
    }
}
