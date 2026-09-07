<?php

declare(strict_types=1);

namespace Tests\Feature\DemoDataset;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Order\Models\Order;
use Tests\TestCase;

/**
 * Pre-Production Readiness (C.10) — Demo Data Safety requirements:
 * demo:seed is clearly separated from db:seed, refuses production unless
 * explicitly overridden, and never produces demo commerce data as a side
 * effect of the normal seeding path.
 */
class DemoSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_db_seed_never_creates_demo_commerce_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->count());
        $this->assertSame(0, Order::where('order_number', 'like', 'DEMO-%')->count());
    }

    public function test_demo_seed_refuses_to_run_in_production_without_the_override_flag(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('demo:seed')->assertFailed();

        $this->assertSame(0, Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->count());
    }

    public function test_demo_seed_creates_the_demo_tenant_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertSame(1, Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->count());

        $tenant = Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->firstOrFail();
        $firstOrderCount = Order::where('tenant_id', $tenant->id)->count();

        // Re-running must never duplicate rows.
        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertSame(1, Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->count());
        $this->assertSame($firstOrderCount, Order::where('tenant_id', $tenant->id)->count());
    }
}
