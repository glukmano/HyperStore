<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Marketplace\Contracts\PayoutServiceInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\Enums\PayoutAllocationStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorPayableAvailabilityStatus;
use Modules\Marketplace\Enums\VendorPayableEntryType;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorCommissionRule;
use Modules\Marketplace\Models\VendorInvitation;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorPayableEntry;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorUser;
use Modules\Marketplace\Services\VendorInvitationService;
use Tests\TestCase;

class PostgreSqlMarketplaceConcurrencyTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private VendorPlan $plan;

    private Vendor $vendor;

    private User $ownerUser;

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
            $this->markTestSkipped('PostgreSqlMarketplaceConcurrencyTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenantA = Tenant::create(['name' => 'Concurrent MP Tenant A', 'slug' => 'cc-mp-a-'.uniqid(), 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Concurrent MP Tenant B', 'slug' => 'cc-mp-b-'.uniqid(), 'status' => 'active']);

        $this->storeA = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'CC Store A', 'slug' => 'cc-store-'.uniqid(), 'status' => 'active']);

        $this->plan = VendorPlan::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Concurrency Plan',
            'code' => 'plan-cc-'.uniqid(),
            'staff_limit' => 2,
            'commission_rate_bps' => 1000,
            'fixed_fee_minor' => 100,
        ]);

        $this->ownerUser = User::factory()->create();

        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $this->plan->id,
            'name' => 'Concurrency Vendor',
            'platform_slug' => 'cc-vendor-'.uniqid(),
            'legal_name' => 'Concurrency Vendor Corp',
            'email' => 'cc@vendor.com',
            'payout_currency' => 'EUR',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        VendorUser::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendor->id,
            'user_id' => $this->ownerUser->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    /**
     * Helper to spawn synchronized concurrent worker scripts via proc_open with a file barrier.
     *
     * @param  array<int, string>  $scripts
     * @return array<int, array{exit_code: int, stdout: string, stderr: string}>
     */
    private function executeConcurrently(array $scripts): array
    {
        $barrierFile = sys_get_temp_dir().'/mp_barrier_'.uniqid();
        $processes = [];
        $pipes = [];

        foreach ($scripts as $idx => $script) {
            $syncedScript = str_replace('// __BARRIER_WAIT__', "while (!file_exists('{$barrierFile}')) { usleep(500); }", $script);

            $tmpFile = sys_get_temp_dir()."/worker_mp_{$idx}_".uniqid().'.php';
            file_put_contents($tmpFile, $syncedScript);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $cmd = 'php '.escapeshellarg($tmpFile);
            $proc = proc_open($cmd, $descriptors, $pipes[$idx]);
            $processes[$idx] = [
                'resource' => $proc,
                'tmp_file' => $tmpFile,
            ];
        }

        usleep(50000); // 50ms buffer
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

            $results[$idx] = [
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
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

    public function test_race_a_concurrent_global_slug_registration(): void
    {
        $sharedSlug = 'slug-race-'.uniqid();
        $bootstrap = $this->getBootstrapScript();

        $workerCode = function (int $tenantId, string $slug, int $planId) use ($bootstrap): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Services\\VendorRegistrationService::class);
    \$user = \\App\\Models\\User::factory()->create();
    \$dto = new \\Modules\\Marketplace\\DTOs\\VendorRegistrationDTO(
        tenantId: {$tenantId},
        name: 'Vendor ' . uniqid(),
        platformSlug: '{$slug}',
        legalName: 'Legal ' . uniqid(),
        email: uniqid() . '@race.com',
        vendorPlanId: {$planId},
        ownerUserId: \$user->id
    );
    \$vendor = \$service->registerVendor(\$dto);
    echo 'SUCCESS:' . \$vendor->id;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";
        };

        $scripts = [
            $workerCode($this->tenantA->id, $sharedSlug, $this->plan->id),
            $workerCode($this->tenantB->id, $sharedSlug, $this->plan->id),
        ];

        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $failureCount = 0;
        foreach ($results as $res) {
            if ($res['exit_code'] === 0 && str_starts_with($res['stdout'], 'SUCCESS:')) {
                $successCount++;
            } else {
                $failureCount++;
                $this->assertTrue(
                    str_contains($res['stdout'], 'SlugAlreadyTakenException') ||
                    str_contains($res['stdout'], 'QueryException') ||
                    str_contains($res['stdout'], 'UniqueConstraintViolationException')
                );
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one registration should succeed for duplicate global slug.');
        $this->assertSame(1, $failureCount, 'Exactly one registration should fail for duplicate global slug.');
    }

    public function test_race_c_concurrent_active_owner_creation(): void
    {
        $bootstrap = $this->getBootstrapScript();
        $vendorId = $this->vendor->id;
        $tenantId = $this->tenantA->id;

        $workerCode = function () use ($bootstrap, $tenantId, $vendorId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$user = \\App\\Models\\User::factory()->create();
    \\Modules\\Marketplace\\Models\\VendorUser::create([
        'tenant_id' => {$tenantId},
        'vendor_id' => {$vendorId},
        'user_id' => \$user->id,
        'role' => 'owner',
        'is_active' => true,
    ]);
    echo 'SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e);
    exit(1);
}
";
        };

        // Vendor already has 1 active owner created in setUp.
        // Two concurrent workers attempt to insert another active owner.
        $scripts = [$workerCode(), $workerCode()];
        $results = $this->executeConcurrently($scripts);

        foreach ($results as $res) {
            // Both must fail because vendor already has an active owner and partial index uq_vendor_single_owner guards it
            $this->assertSame(1, $res['exit_code']);
            $this->assertTrue(str_contains($res['stdout'], 'UniqueConstraintViolationException') || str_contains($res['stdout'], 'QueryException'));
        }

        $activeOwnerCount = VendorUser::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->count();
        $this->assertSame(1, $activeOwnerCount);
    }

    public function test_race_d_concurrent_ownership_transfer(): void
    {
        $bootstrap = $this->getBootstrapScript();
        $vendorId = $this->vendor->id;
        $tenantId = $this->tenantA->id;
        $userCandidate1 = User::factory()->create();
        $userCandidate2 = User::factory()->create();

        $workerCode = function (int $candidateUserId) use ($bootstrap, $tenantId, $vendorId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Services\\VendorOwnershipService::class);
    \$newOwner = \$service->transferOwnership({$tenantId}, {$vendorId}, {$candidateUserId});
    echo 'SUCCESS:' . \$newOwner->user_id;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e);
    exit(1);
}
";
        };

        $scripts = [
            $workerCode($userCandidate1->id),
            $workerCode($userCandidate2->id),
        ];

        $results = $this->executeConcurrently($scripts);

        // At the end, exactly 1 active owner must exist
        $activeOwnerCount = VendorUser::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->count();
        $this->assertSame(1, $activeOwnerCount);
    }

    public function test_race_k_concurrent_payout_request_overdraw(): void
    {
        // Accrue 10,000 EUR
        VendorPayableEntry::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'test_order',
            'source_uuid' => 'race-k-'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $vendorId = $this->vendor->id;
        $tenantId = $this->tenantA->id;

        // Two concurrent workers attempt to request 8,000 EUR payout each (total 16,000 > 10,000)
        $workerCode = function () use ($bootstrap, $tenantId, $vendorId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Contracts\\PayoutServiceInterface::class);
    \$req = \$service->requestPayout({$tenantId}, {$vendorId}, 8000, 'EUR');
    echo 'SUCCESS:' . \$req->id;
    exit(0);
} catch (\\Modules\\Marketplace\\Exceptions\\InsufficientPayableBalanceException \$e) {
    echo 'FAILED:INSUFFICIENT_BALANCE';
    exit(1);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(2);
}
";
        };

        $scripts = [$workerCode(), $workerCode()];
        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $insufficientCount = 0;
        foreach ($results as $res) {
            if ($res['exit_code'] === 0 && str_starts_with($res['stdout'], 'SUCCESS:')) {
                $successCount++;
            } elseif ($res['exit_code'] === 1 && str_starts_with($res['stdout'], 'FAILED:INSUFFICIENT_BALANCE')) {
                $insufficientCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one payout request should succeed.');
        $this->assertSame(1, $insufficientCount, 'Second concurrent request must fail with insufficient balance.');
    }

    public function test_race_l_concurrent_payout_finalization_idempotency(): void
    {
        // Accrue 10,000 EUR
        $earning = VendorPayableEntry::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'test_order',
            'source_uuid' => 'race-l-'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        $payoutService = app(PayoutServiceInterface::class);
        $request = $payoutService->requestPayout($this->tenantA->id, $this->vendor->id, 5000, 'EUR');
        $approved = $payoutService->approvePayout($request->id, $this->ownerUser->id);
        $processing = $payoutService->markProcessing($approved->id);

        $bootstrap = $this->getBootstrapScript();
        $payoutId = $processing->id;

        // Two concurrent workers attempt to finalize the same payout request
        $workerCode = function () use ($bootstrap, $payoutId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Contracts\\PayoutServiceInterface::class);
    \$finalized = \$service->finalizePayout({$payoutId}, 'TX-SETTLE-CONCURRENT');
    echo 'SUCCESS:' . \$finalized->status->value;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";
        };

        $scripts = [$workerCode(), $workerCode()];
        $results = $this->executeConcurrently($scripts);

        foreach ($results as $res) {
            $this->assertSame(0, $res['exit_code'], 'Both workers should exit 0 due to idempotent finalization replay: '.$res['stdout'].$res['stderr']);
            $this->assertSame('SUCCESS:paid', $res['stdout']);
        }

        // Assert: EXACTLY ONE payout_disbursement entry exists in database
        $disbursementCount = VendorPayableEntry::where('source_type', 'payout_request')
            ->where('source_uuid', $request->uuid)
            ->count();
        $this->assertSame(1, $disbursementCount, 'Exactly one economic disbursement entry must be created.');

        // Assert allocation consumed
        $alloc = $request->allocations()->first();
        $this->assertSame(PayoutAllocationStatus::Consumed, $alloc->status);
    }

    public function test_race_b_concurrent_custom_domain_claim(): void
    {
        $sharedDomain = 'shop-race-'.uniqid().'.com';
        $bootstrap = $this->getBootstrapScript();
        $vendorAId = $this->vendor->id;
        $tenantAId = $this->tenantA->id;
        $tenantBId = $this->tenantB->id;

        $workerCode = function (int $tId, int $vId, string $domain) use ($bootstrap): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Modules\\Marketplace\\Models\\VendorDomain::create([
        'tenant_id' => {$tId},
        'vendor_id' => {$vId},
        'domain' => '{$domain}',
        'verification_token' => 'tok_' . uniqid(),
    ]);
    echo 'SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e);
    exit(1);
}
";
        };

        $scripts = [
            $workerCode($tenantAId, $vendorAId, $sharedDomain),
            $workerCode($tenantBId, $vendorAId, $sharedDomain),
        ];

        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $failCount = 0;
        foreach ($results as $res) {
            if ($res['exit_code'] === 0) {
                $successCount++;
            } else {
                $failCount++;
                $this->assertTrue(str_contains($res['stdout'], 'UniqueConstraintViolationException') || str_contains($res['stdout'], 'QueryException'));
            }
        }

        $this->assertSame(1, $successCount);
        $this->assertSame(1, $failCount);
    }

    public function test_race_e_concurrent_staff_invitation_acceptance(): void
    {
        $invitationService = app(VendorInvitationService::class);
        $res = $invitationService->inviteStaff($this->tenantA->id, $this->vendor->id, 'single-seat@race.com', VendorRole::Staff);
        $token = $res['plaintext_token'];

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $bootstrap = $this->getBootstrapScript();

        $workerCode = function (int $userId, string $tkn) use ($bootstrap): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Services\\VendorInvitationService::class);
    \$user = \\App\\Models\\User::find({$userId});
    \$member = \$service->acceptInvitation('{$tkn}', \$user);
    echo 'SUCCESS:' . \$member->id;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e);
    exit(1);
}
";
        };

        $scripts = [
            $workerCode($user1->id, $token),
            $workerCode($user2->id, $token),
        ];

        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $failCount = 0;
        foreach ($results as $r) {
            if ($r['exit_code'] === 0) {
                $successCount++;
            } else {
                $failCount++;
                $this->assertStringContainsString('VendorInvitationException', $r['stdout']);
            }
        }

        $this->assertSame(1, $successCount, 'Single-use invitation token must only accept once.');
        $this->assertSame(1, $failCount, 'Second concurrent accept must fail.');
    }

    public function test_race_f_concurrent_vendor_listing_canonical_product_deduplication(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'CANON-RACE-'.uniqid(),
            'status' => 'active',
        ]);

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenantA->id;
        $vendorId = $this->vendor->id;
        $prodId = $product->id;

        $workerCode = function () use ($bootstrap, $tenantId, $vendorId, $prodId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Modules\\Marketplace\\Models\\VendorListing::create([
        'tenant_id' => {$tenantId},
        'vendor_id' => {$vendorId},
        'product_id' => {$prodId},
        'product_variant_id' => null,
        'vendor_sku' => 'VSKU-' . uniqid(),
    ]);
    echo 'SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e);
    exit(1);
}
";
        };

        $scripts = [$workerCode(), $workerCode()];
        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $failCount = 0;
        foreach ($results as $r) {
            if ($r['exit_code'] === 0) {
                $successCount++;
            } else {
                $failCount++;
                $this->assertTrue(str_contains($r['stdout'], 'UniqueConstraintViolationException') || str_contains($r['stdout'], 'QueryException'));
            }
        }

        $this->assertSame(1, $successCount);
        $this->assertSame(1, $failCount);
    }

    public function test_race_h_concurrent_commission_rule_scope_overlap(): void
    {
        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenantA->id;
        $vendorId = $this->vendor->id;

        $workerCode = function () use ($bootstrap, $tenantId, $vendorId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Modules\\Marketplace\\Models\\VendorCommissionRule::create([
        'tenant_id' => {$tenantId},
        'vendor_id' => {$vendorId},
        'category_id' => null,
        'rate_basis_points' => rand(500, 2000),
        'currency' => 'EUR',
        'is_active' => true,
    ]);
    echo 'SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e);
    exit(1);
}
";
        };

        $scripts = [$workerCode(), $workerCode()];
        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $failCount = 0;
        foreach ($results as $r) {
            if ($r['exit_code'] === 0) {
                $successCount++;
            } else {
                $failCount++;
                $this->assertTrue(str_contains($r['stdout'], 'UniqueConstraintViolationException') || str_contains($r['stdout'], 'QueryException'));
            }
        }

        $this->assertSame(1, $successCount);
        $this->assertSame(1, $failCount);
    }

    public function test_race_payout_reservation_7k_vs_7k_concurrency(): void
    {
        // Available balance: 10,000 EUR
        VendorPayableEntry::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'race-7k-'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenantA->id;
        $vendorId = $this->vendor->id;

        // Two concurrent workers both attempt to reserve 7,000 EUR from a 10,000 EUR pool
        $workerCode = function () use ($bootstrap, $tenantId, $vendorId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Contracts\\PayoutServiceInterface::class);
    \$req = \$service->requestPayout({$tenantId}, {$vendorId}, 7000, 'EUR');
    echo 'SUCCESS:' . \$req->id;
    exit(0);
} catch (\\Modules\\Marketplace\\Exceptions\\InsufficientPayableBalanceException \$e) {
    echo 'FAILED_INSUFFICIENT:' . \$e->getMessage();
    exit(1);
} catch (\\Throwable \$e) {
    echo 'FAILED_OTHER:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";
        };

        $scripts = [$workerCode(), $workerCode()];
        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $insufficientCount = 0;

        foreach ($results as $res) {
            if ($res['exit_code'] === 0 && str_starts_with($res['stdout'], 'SUCCESS:')) {
                $successCount++;
            } elseif (str_contains($res['stdout'], 'FAILED_INSUFFICIENT:')) {
                $insufficientCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one 7k reservation must succeed from 10k pool.');
        $this->assertSame(1, $insufficientCount, 'The competing 7k reservation must fail with InsufficientPayableBalanceException.');

        // Subledger asserts: 7k reserved, exactly 3k withdrawable balance remains
        $subledger = app(VendorPayableSubledgerServiceInterface::class);
        $bal = $subledger->getBalances($tenantId, $vendorId, 'EUR');
        $this->assertSame(7000, $bal->reservedForPayoutMinor);
        $this->assertSame(3000, $bal->withdrawableBalanceMinor);
    }

    public function test_sequential_partial_payout_4k_settle_then_6k_leaves_exact_zero_residual(): void
    {
        // 1. Accrue 10,000 EUR
        $earning = VendorPayableEntry::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'race-partial-'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        $payoutService = app(PayoutServiceInterface::class);
        $subledger = app(VendorPayableSubledgerServiceInterface::class);

        // 2. Reserve 4k
        $req1 = $payoutService->requestPayout($this->tenantA->id, $this->vendor->id, 4000, 'EUR');
        $bal1 = $subledger->getBalances($this->tenantA->id, $this->vendor->id, 'EUR');
        $this->assertSame(4000, $bal1->reservedForPayoutMinor);
        $this->assertSame(6000, $bal1->withdrawableBalanceMinor);

        // 3. Settle 4k
        $app1 = $payoutService->approvePayout($req1->id, $this->ownerUser->id);
        $proc1 = $payoutService->markProcessing($app1->id);
        $payoutService->finalizePayout($proc1->id, 'TX-4K-SETTLE');

        $balAfterSettle1 = $subledger->getBalances($this->tenantA->id, $this->vendor->id, 'EUR');
        $this->assertSame(6000, $balAfterSettle1->availableEconomicBalanceMinor);
        $this->assertSame(0, $balAfterSettle1->reservedForPayoutMinor);
        $this->assertSame(6000, $balAfterSettle1->withdrawableBalanceMinor);

        // 4. Reserve remaining 6k
        $req2 = $payoutService->requestPayout($this->tenantA->id, $this->vendor->id, 6000, 'EUR');
        $bal2 = $subledger->getBalances($this->tenantA->id, $this->vendor->id, 'EUR');
        $this->assertSame(6000, $bal2->reservedForPayoutMinor);
        $this->assertSame(0, $bal2->withdrawableBalanceMinor);

        // 5. Settle 6k
        $app2 = $payoutService->approvePayout($req2->id, $this->ownerUser->id);
        $proc2 = $payoutService->markProcessing($app2->id);
        $payoutService->finalizePayout($proc2->id, 'TX-6K-SETTLE');

        // Exact zero residual, no double subtraction
        $balFinal = $subledger->getBalances($this->tenantA->id, $this->vendor->id, 'EUR');
        $this->assertSame(0, $balFinal->availableEconomicBalanceMinor);
        $this->assertSame(0, $balFinal->reservedForPayoutMinor);
        $this->assertSame(0, $balFinal->withdrawableBalanceMinor);
    }

    public function test_race_duplicate_payable_source_accrual_fails_closed(): void
    {
        $sourceUuid = 'ord-item-race-'.uniqid();
        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenantA->id;
        $vendorId = $this->vendor->id;

        $workerCode = function () use ($bootstrap, $tenantId, $vendorId, $sourceUuid): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$entry = \\Modules\\Marketplace\\Models\\VendorPayableEntry::create([
        'tenant_id' => {$tenantId},
        'vendor_id' => {$vendorId},
        'entry_type' => \\Modules\\Marketplace\\Enums\\VendorPayableEntryType::Earning,
        'source_type' => 'order_item',
        'source_uuid' => '{$sourceUuid}',
        'currency' => 'EUR',
        'amount_minor' => 5000,
        'commission_amount_minor' => 500,
        'net_amount_minor' => 4500,
        'availability_status' => \\Modules\\Marketplace\\Enums\\VendorPayableAvailabilityStatus::Available,
    ]);
    echo 'SUCCESS:' . \$entry->id;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e);
    exit(1);
}
";
        };

        $scripts = [$workerCode(), $workerCode()];
        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $failCount = 0;
        foreach ($results as $r) {
            if ($r['exit_code'] === 0) {
                $successCount++;
            } else {
                $failCount++;
                $this->assertTrue(str_contains($r['stdout'], 'UniqueConstraintViolationException') || str_contains($r['stdout'], 'QueryException'));
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one earning entry can be created for unique source movement.');
        $this->assertSame(1, $failCount, 'Duplicate earning attempt must be rejected by PostgreSQL unique constraint.');

        $count = VendorPayableEntry::where('tenant_id', $tenantId)->where('source_uuid', $sourceUuid)->count();
        $this->assertSame(1, $count);
    }

    public function test_race_payout_request_vs_vendor_suspension(): void
    {
        $vendorForSuspension = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $this->plan->id,
            'name' => 'Suspension Race Vendor',
            'platform_slug' => 'susp-race-'.uniqid(),
            'legal_name' => 'Suspension LLC',
            'email' => 'susp_race@test.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        // Accrue 5,000 EUR
        VendorPayableEntry::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $vendorForSuspension->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'race-susp-'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 5000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 5000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenantA->id;
        $vendorId = $vendorForSuspension->id;

        // Worker 1: Attempts payout request
        $worker1 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Contracts\\PayoutServiceInterface::class);
    \$req = \$service->requestPayout({$tenantId}, {$vendorId}, 5000, 'EUR');
    echo 'PAYOUT_SUCCESS:' . \$req->id;
    exit(0);
} catch (\\Modules\\Marketplace\\Exceptions\\VendorOperationalStatusException \$e) {
    echo 'PAYOUT_REJECTED_SUSPENDED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Concurrently suspends the vendor
        $worker2 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        \$v = \\Modules\\Marketplace\\Models\\Vendor::lockForUpdate()->find({$vendorId});
        if (\$v !== null) {
            \$v->operational_status = \\Modules\\Marketplace\\Enums\\VendorOperationalStatus::Suspended;
            \$v->save();
        }
    });
    echo 'SUSPENSION_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code'], $results[0]['stdout'].$results[0]['stderr']);
        $this->assertSame(0, $results[1]['exit_code'], $results[1]['stdout'].$results[1]['stderr']);
        $this->assertSame('SUSPENSION_SUCCESS', $results[1]['stdout']);
        $this->assertTrue(
            str_starts_with($results[0]['stdout'], 'PAYOUT_SUCCESS:') ||
            str_starts_with($results[0]['stdout'], 'PAYOUT_REJECTED_SUSPENDED:'),
            'Payout must either succeed before lock or be safely rejected once suspended.'
        );

        // Final operational state must be suspended
        $vendorForSuspension->refresh();
        $this->assertSame(VendorOperationalStatus::Suspended, $vendorForSuspension->operational_status);
    }

    public function test_race_subscription_activation_vs_auto_approval(): void
    {
        $autoPlan = VendorPlan::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Auto Approval Plan',
            'code' => 'plan-auto-'.uniqid(),
            'auto_approval' => true,
        ]);
        // Add a paid price to plan
        $autoPlan->prices()->create([
            'tenant_id' => $this->tenantA->id,
            'currency' => 'EUR',
            'monthly_fee_minor' => 2900,
        ]);

        $candidateVendor = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $autoPlan->id,
            'name' => 'Auto App Vendor',
            'platform_slug' => 'auto-app-'.uniqid(),
            'legal_name' => 'Auto LLC',
            'email' => 'auto_app@test.com',
            'operational_status' => VendorOperationalStatus::PendingApproval,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $vendorId = $candidateVendor->id;
        $planId = $autoPlan->id;

        // Worker 1: Activates subscription
        $worker1 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$entitlement = app(\\Modules\\Marketplace\\Contracts\\VendorPlanSubscriptionEntitlementServiceInterface::class);
    \$vendor = \\Modules\\Marketplace\\Models\\Vendor::findOrFail({$vendorId});
    \$plan = \\Modules\\Marketplace\\Models\\VendorPlan::findOrFail({$planId});
    \$sub = \$entitlement->activateSubscription(\$vendor, \$plan, 'test_fake');
    echo 'SUB_ACTIVATED:' . \$sub->id;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Evaluates approval policy
        $worker2 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$policy = app(\\Modules\\Marketplace\\Contracts\\VendorApprovalPolicyInterface::class);
    \$vendor = \\Modules\\Marketplace\\Models\\Vendor::findOrFail({$vendorId});
    \$approved = \$policy->canAutoApprove(\$vendor);
    echo 'APPROVAL_RESULT:' . (\$approved ? 'true' : 'false');
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame(0, $results[1]['exit_code']);
        $this->assertStringStartsWith('SUB_ACTIVATED:', $results[0]['stdout']);
        $this->assertTrue(
            $results[1]['stdout'] === 'APPROVAL_RESULT:true' || $results[1]['stdout'] === 'APPROVAL_RESULT:false',
            'Approval must evaluate authoritatively to true or false without error.'
        );
    }

    public function test_race_approval_vs_suspension_concurrency(): void
    {
        $vendorToTest = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $this->plan->id,
            'name' => 'Approval Suspension Vendor',
            'platform_slug' => 'app-susp-'.uniqid(),
            'legal_name' => 'App Susp LLC',
            'email' => 'app_susp@test.com',
            'operational_status' => VendorOperationalStatus::PendingApproval,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $vendorId = $vendorToTest->id;

        // Worker 1: Attempts approval transition (PendingApproval -> Active)
        $worker1 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        \$v = \\Modules\\Marketplace\\Models\\Vendor::lockForUpdate()->find({$vendorId});
        if (\$v->operational_status !== \\Modules\\Marketplace\\Enums\\VendorOperationalStatus::PendingApproval) {
            throw new \\DomainException('CANNOT_APPROVE: Stale or invalid operational status: ' . \$v->operational_status->value);
        }
        \$v->operational_status = \\Modules\\Marketplace\\Enums\\VendorOperationalStatus::Active;
        \$v->save();
    });
    echo 'APPROVAL_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'APPROVAL_FAILED:' . \$e->getMessage();
    exit(0);
}
";

        // Worker 2: Attempts suspension transition (-> Suspended)
        $worker2 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        \$v = \\Modules\\Marketplace\\Models\\Vendor::lockForUpdate()->find({$vendorId});
        if (!\$v->operational_status->canTransitionTo(\\Modules\\Marketplace\\Enums\\VendorOperationalStatus::Suspended)) {
            throw new \\DomainException('CANNOT_SUSPEND: Status cannot transition to suspended: ' . \$v->operational_status->value);
        }
        \$v->operational_status = \\Modules\\Marketplace\\Enums\\VendorOperationalStatus::Suspended;
        \$v->save();
    });
    echo 'SUSPENSION_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'SUSPENSION_FAILED:' . \$e->getMessage();
    exit(0);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame(0, $results[1]['exit_code']);

        $vendorToTest->refresh();

        // Monotonic invariant: In both interleavings, stale approval NEVER overwrites committed suspension
        $this->assertSame(VendorOperationalStatus::Suspended, $vendorToTest->operational_status);
        $this->assertSame('SUSPENSION_SUCCESS', $results[1]['stdout']);
    }

    public function test_race_staff_quota_concurrency(): void
    {
        $quotaPlan = VendorPlan::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Quota 2 Plan',
            'code' => 'plan-q2-'.uniqid(),
            'staff_limit' => 2,
        ]);

        $vendor = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $quotaPlan->id,
            'name' => 'Staff Quota Vendor',
            'platform_slug' => 'staff-quota-'.uniqid(),
            'legal_name' => 'Quota LLC',
            'email' => 'quota@test.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        // Existing active staff: 1 (Owner)
        VendorUser::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $vendor->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenantA->id;
        $vendorId = $vendor->id;

        // Two concurrent workers attempt to invite staff (limit = 2, current = 1, exactly 1 slot remains)
        $workerCode = function (string $email) use ($bootstrap, $tenantId, $vendorId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$service = app(\\Modules\\Marketplace\\Services\\VendorInvitationService::class);
    \$res = \$service->inviteStaff({$tenantId}, {$vendorId}, '{$email}', \\Modules\\Marketplace\\Enums\\VendorRole::Staff);
    echo 'SUCCESS:' . \$res['invitation']->id;
    exit(0);
} catch (\\Modules\\Marketplace\\Exceptions\\VendorInvitationException \$e) {
    echo 'QUOTA_EXCEEDED:' . \$e->getMessage();
    exit(1);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";
        };

        $scripts = [
            $workerCode('staff1@quota.com'),
            $workerCode('staff2@quota.com'),
        ];

        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $quotaExceededCount = 0;

        foreach ($results as $res) {
            if ($res['exit_code'] === 0 && str_starts_with($res['stdout'], 'SUCCESS:')) {
                $successCount++;
            } elseif (str_contains($res['stdout'], 'QUOTA_EXCEEDED:')) {
                $quotaExceededCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one staff invitation must succeed when 1 slot remains.');
        $this->assertSame(1, $quotaExceededCount, 'The competing worker must fail with quotaExceeded.');

        $totalMembersAndInvites = VendorUser::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)->count()
            + VendorInvitation::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)->count();

        $this->assertSame(2, $totalMembersAndInvites, 'Total members + pending invites must never exceed staff_limit (2).');
    }

    public function test_race_listing_quota_concurrency(): void
    {
        $listingQuotaPlan = VendorPlan::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Listing Quota 2 Plan',
            'code' => 'plan-lq2-'.uniqid(),
            'product_limit' => 2,
        ]);

        $vendor = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $listingQuotaPlan->id,
            'name' => 'Listing Quota Vendor',
            'platform_slug' => 'listing-q-'.uniqid(),
            'legal_name' => 'Listing Quota LLC',
            'email' => 'list_q@test.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        // Product 1 (existing listing)
        $p1 = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'PROD-LQ-1-'.uniqid(),
            'status' => 'active',
        ]);
        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $vendor->id,
            'product_id' => $p1->id,
            'product_variant_id' => null,
            'vendor_sku' => 'VSKU-LQ-1',
        ]);

        // Products 2 and 3 for concurrent workers
        $p2 = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'PROD-LQ-2-'.uniqid(),
            'status' => 'active',
        ]);
        $p3 = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'PROD-LQ-3-'.uniqid(),
            'status' => 'active',
        ]);

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenantA->id;
        $vendorId = $vendor->id;
        $p2Id = $p2->id;
        $p3Id = $p3->id;

        $workerCode = function (int $productId, string $sku) use ($bootstrap, $tenantId, $vendorId): string {
            return "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$listing = \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        return \\Modules\\Marketplace\\Models\\VendorListing::create([
        'tenant_id' => {$tenantId},
        'vendor_id' => {$vendorId},
        'product_id' => {$productId},
        'product_variant_id' => null,
        'vendor_sku' => '{$sku}',
        ]);
    });
    echo 'SUCCESS:' . \$listing->id;
    exit(0);
} catch (\\Modules\\Marketplace\\Exceptions\\VendorListingQuotaException \$e) {
    echo 'QUOTA_EXCEEDED:' . \$e->getMessage();
    exit(1);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";
        };

        $scripts = [
            $workerCode($p2Id, 'VSKU-LQ-2'),
            $workerCode($p3Id, 'VSKU-LQ-3'),
        ];

        $results = $this->executeConcurrently($scripts);

        $successCount = 0;
        $quotaExceededCount = 0;

        foreach ($results as $res) {
            if ($res['exit_code'] === 0 && str_starts_with($res['stdout'], 'SUCCESS:')) {
                $successCount++;
            } elseif (str_contains($res['stdout'], 'QUOTA_EXCEEDED:')) {
                $quotaExceededCount++;
            }
        }

        $this->assertSame(1, $successCount, 'Exactly one concurrent listing creation must succeed when 1 slot remains.');
        $this->assertSame(1, $quotaExceededCount, 'The competing listing creation must be rejected with VendorListingQuotaException.');

        $finalListingCount = VendorListing::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)->count();
        $this->assertSame(2, $finalListingCount, 'Active listings must never exceed product_limit (2).');
    }

    public function test_race_plan_downgrade_vs_listing_quota_mutation(): void
    {
        $highPlan = VendorPlan::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'High Plan',
            'code' => 'plan-high-'.uniqid(),
            'product_limit' => 5,
        ]);
        $lowPlan = VendorPlan::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Low Plan',
            'code' => 'plan-low-'.uniqid(),
            'product_limit' => 1,
        ]);

        $vendor = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $highPlan->id,
            'name' => 'Downgrade Race Vendor',
            'platform_slug' => 'down-race-'.uniqid(),
            'legal_name' => 'Downgrade LLC',
            'email' => 'down@test.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        // Already has 1 listing
        $p1 = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'PROD-DOWN-1-'.uniqid(),
            'status' => 'active',
        ]);
        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $vendor->id,
            'product_id' => $p1->id,
            'product_variant_id' => null,
            'vendor_sku' => 'VSKU-DOWN-1',
        ]);

        $p2 = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'PROD-DOWN-2-'.uniqid(),
            'status' => 'active',
        ]);

        $bootstrap = $this->getBootstrapScript();
        $tenantId = $this->tenantA->id;
        $vendorId = $vendor->id;
        $lowPlanId = $lowPlan->id;
        $p2Id = $p2->id;

        // Worker 1: Downgrades vendor to low plan (product_limit = 1) under lock
        $worker1 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        \$v = \\Modules\\Marketplace\\Models\\Vendor::lockForUpdate()->find({$vendorId});
        \$v->vendor_plan_id = {$lowPlanId};
        \$v->save();
    });
    echo 'DOWNGRADE_SUCCESS';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'DOWNGRADE_FAILED:' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Attempts to add second listing
        $worker2 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$l = \\Modules\\Marketplace\\Models\\VendorListing::create([
        'tenant_id' => {$tenantId},
        'vendor_id' => {$vendorId},
        'product_id' => {$p2Id},
        'product_variant_id' => null,
        'vendor_sku' => 'VSKU-DOWN-2',
    ]);
    echo 'LISTING_SUCCESS:' . \$l->id;
    exit(0);
} catch (\\Modules\\Marketplace\\Exceptions\\VendorListingQuotaException \$e) {
    echo 'LISTING_QUOTA_EXCEEDED:' . \$e->getMessage();
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame(0, $results[1]['exit_code']);
        $this->assertSame('DOWNGRADE_SUCCESS', $results[0]['stdout']);

        $vendor->refresh();
        $this->assertSame($lowPlan->id, $vendor->vendor_plan_id);

        $finalCount = VendorListing::where('tenant_id', $tenantId)->where('vendor_id', $vendorId)->count();
        $this->assertTrue($finalCount === 1 || $finalCount === 2);
    }

    public function test_race_commission_rule_mutation_vs_checkout_ready_snapshot(): void
    {
        // Initial rule: 1000 bps (10%) + 100 fixed fee
        $rule = VendorCommissionRule::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => null,
            'rate_basis_points' => 1000,
            'fixed_fee_minor' => 100,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $bootstrap = $this->getBootstrapScript();
        $ruleId = $rule->id;
        $tenantId = $this->tenantA->id;
        $vendorId = $this->vendor->id;

        // Worker 1: Atomic resolution & calculation
        $worker1 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \$calc = app(\\Modules\\Marketplace\\Contracts\\VendorCommissionQuoteServiceInterface::class);
    \$quote = \$calc->quoteCommission(
        categoryId: null,
        tenantId: {$tenantId},
        vendorId: {$vendorId},
        basisMinor: 10000,
        currency: 'EUR'
    );
    echo 'SNAPSHOT:' . \$quote->rateBps . ':' . \$quote->fixedFeeMinor . ':' . \$quote->commissionAmountMinor;
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        // Worker 2: Mutates the rule atomically (to 2500 bps, 300 fixed fee)
        $worker2 = "{$bootstrap}
// __BARRIER_WAIT__
try {
    \\Illuminate\\Support\\Facades\\DB::transaction(function () {
        \$r = \\Modules\\Marketplace\\Models\\VendorCommissionRule::lockForUpdate()->find({$ruleId});
        \$r->rate_basis_points = 2500;
        \$r->fixed_fee_minor = 300;
        \$r->save();
    });
    echo 'RULE_MUTATED';
    exit(0);
} catch (\\Throwable \$e) {
    echo 'FAILED:' . get_class(\$e) . ':' . \$e->getMessage();
    exit(1);
}
";

        $results = $this->executeConcurrently([$worker1, $worker2]);

        $this->assertSame(0, $results[0]['exit_code'], $results[0]['stdout'].$results[0]['stderr']);
        $this->assertSame(0, $results[1]['exit_code'], $results[1]['stdout'].$results[1]['stderr']);
        $this->assertSame('RULE_MUTATED', $results[1]['stdout']);

        // Snapshot must be EITHER coherent Old Rule (1000 bps + 100 fixed = 1100) OR coherent New Rule (2500 bps + 300 fixed = 2800)
        // FORBIDDEN: Any mixed snapshot (e.g. 1000 bps + 300 fixed or 2500 bps + 100 fixed)
        $expectedOld = 'SNAPSHOT:1000:100:1100';
        $expectedNew = 'SNAPSHOT:2500:300:2800';

        $this->assertTrue(
            $results[0]['stdout'] === $expectedOld || $results[0]['stdout'] === $expectedNew,
            'Snapshot must freeze exactly one coherent rule state, never a mixed or partially applied rule: '.$results[0]['stdout']
        );
    }
}
