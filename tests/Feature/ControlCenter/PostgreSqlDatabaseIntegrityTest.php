<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Exceptions\TenantLicenseInactiveException;
use App\Core\SuperAdmin\Models\ImpersonationEvent;
use App\Core\SuperAdmin\Models\ImpersonationSession;
use App\Core\SuperAdmin\Models\TenantLicense;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Tests\TestCase;

class PostgreSqlDatabaseIntegrityTest extends TestCase
{
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
            $this->markTestSkipped('PostgreSQL engine required for DB integrity tests.');
        }
    }

    public function test_partial_unique_index_prevents_multiple_active_sessions_for_same_impersonator(): void
    {
        $impersonator = User::create([
            'name' => 'Impersonator',
            'email' => 'imp_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => true,
        ]);

        $target1 = User::create([
            'name' => 'Target 1',
            'email' => 'tgt1_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
        ]);

        $target2 = User::create([
            'name' => 'Target 2',
            'email' => 'tgt2_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
        ]);

        // Insert first active session
        ImpersonationSession::create([
            'impersonator_user_id' => $impersonator->id,
            'target_user_id' => $target1->id,
            'status' => 'active',
            'token_hash' => hash('sha256', Str::random(64)),
            'reason' => 'First session',
            'started_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);

        // Second raw insert with status=active must fail at PostgreSQL engine level
        $this->expectException(QueryException::class);

        ImpersonationSession::create([
            'impersonator_user_id' => $impersonator->id,
            'target_user_id' => $target2->id,
            'status' => 'active',
            'token_hash' => hash('sha256', Str::random(64)),
            'reason' => 'Duplicate active attempt',
            'started_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);
    }

    public function test_impersonation_events_trigger_rejects_update_and_delete(): void
    {
        $user = User::create([
            'name' => 'Audit User',
            'email' => 'audit_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
        ]);

        $event = ImpersonationEvent::create([
            'session_uuid' => (string) Str::uuid(),
            'event_type' => 'started',
            'actor_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'metadata' => ['info' => 'immutable audit'],
            'created_at' => CarbonImmutable::now(),
        ]);

        // UPDATE must fail via database trigger
        $updateBlocked = false;
        try {
            DB::statement("UPDATE impersonation_events SET event_type = 'tampered' WHERE id = ?", [$event->id]);
        } catch (\Throwable $e) {
            $updateBlocked = str_contains($e->getMessage(), 'impersonation_events is append-only');
        }
        $this->assertTrue($updateBlocked, 'PostgreSQL trigger must reject UPDATE on impersonation_events.');

        // DELETE must fail via database trigger
        $deleteBlocked = false;
        try {
            DB::statement('DELETE FROM impersonation_events WHERE id = ?', [$event->id]);
        } catch (\Throwable $e) {
            $deleteBlocked = str_contains($e->getMessage(), 'impersonation_events is append-only');
        }
        $this->assertTrue($deleteBlocked, 'PostgreSQL trigger must reject DELETE on impersonation_events.');
    }

    public function test_missing_tenant_license_blocks_checkout_session_creation(): void
    {
        $tenant = Tenant::create([
            'name' => 'No License Tenant',
            'slug' => 'no-lic-'.uniqid(),
            'status' => 'active',
        ]);

        // Explicitly delete auto-provisioned license to simulate missing license
        TenantLicense::where('tenant_id', $tenant->id)->delete();

        $cart = $this->createTestCartForTenant($tenant);
        $orchestrator = app(CheckoutOrchestratorInterface::class);

        try {
            $orchestrator->createFromCart($cart, 'idem-'.uniqid());
            $this->fail('Expected TenantLicenseInactiveException was not thrown for missing license.');
        } catch (TenantLicenseInactiveException $e) {
            $this->assertStringContainsString('missing', $e->getMessage());
        }
    }

    public function test_suspended_tenant_license_blocks_checkout_session_creation(): void
    {
        $tenant = Tenant::create([
            'name' => 'Suspended License Tenant',
            'slug' => 'susp-lic-'.uniqid(),
            'status' => 'active',
        ]);

        $license = TenantLicense::where('tenant_id', $tenant->id)->firstOrFail();
        $license->status = 'suspended';
        $license->save();

        $cart = $this->createTestCartForTenant($tenant);
        $orchestrator = app(CheckoutOrchestratorInterface::class);

        try {
            $orchestrator->createFromCart($cart, 'idem-'.uniqid());
            $this->fail('Expected TenantLicenseInactiveException was not thrown for suspended license.');
        } catch (TenantLicenseInactiveException $e) {
            $this->assertStringContainsString('suspended', $e->getMessage());
        }
    }

    public function test_expired_tenant_license_blocks_checkout_session_creation(): void
    {
        $tenant = Tenant::create([
            'name' => 'Expired License Tenant',
            'slug' => 'exp-lic-'.uniqid(),
            'status' => 'active',
        ]);

        $license = TenantLicense::where('tenant_id', $tenant->id)->firstOrFail();
        $license->valid_until = CarbonImmutable::now()->subMinute();
        $license->save();

        $cart = $this->createTestCartForTenant($tenant);
        $orchestrator = app(CheckoutOrchestratorInterface::class);

        try {
            $orchestrator->createFromCart($cart, 'idem-'.uniqid());
            $this->fail('Expected TenantLicenseInactiveException was not thrown for expired license.');
        } catch (TenantLicenseInactiveException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
    }

    public function test_active_tenant_license_permits_checkout_session_creation(): void
    {
        $tenant = Tenant::create([
            'name' => 'Active License Tenant',
            'slug' => 'act-lic-'.uniqid(),
            'status' => 'active',
        ]);

        $cart = $this->createTestCartForTenant($tenant);
        $orchestrator = app(CheckoutOrchestratorInterface::class);

        $session = $orchestrator->createFromCart($cart, 'idem-'.uniqid());

        $this->assertNotNull($session);
        $this->assertSame($tenant->id, $session->tenant_id);
    }

    private function createTestCartForTenant(Tenant $tenant): Cart
    {
        $store = Store::create([
            'tenant_id' => $tenant->id,
            'code' => 'ST_'.uniqid(),
            'name' => 'Store',
            'slug' => 'st-'.uniqid(),
            'status' => 'active',
        ]);

        $market = Market::create([
            'tenant_id' => $tenant->id,
            'code' => 'US_'.uniqid(),
            'name' => 'US Market',
            'default_currency_code' => 'USD',
            'default_locale_code' => 'en',
            'is_active' => true,
        ]);

        $channel = Channel::create([
            'name' => 'Web',
            'handle' => 'web-'.uniqid(),
            'is_active' => true,
        ]);

        $cart = Cart::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'USD',
            'currency_code' => 'USD',
            'channel' => 'web',
            'status' => 'active',
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'product_type' => 'simple',
            'sku' => 'SKU-'.uniqid(),
            'status' => 'active',
        ]);

        CartLine::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'signature' => hash('sha256', 'test-line-'.uniqid()),
            'unit_price_minor' => 500,
        ]);

        return $cart;
    }
}
