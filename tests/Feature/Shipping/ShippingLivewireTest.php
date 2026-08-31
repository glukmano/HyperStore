<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Shipping\Livewire\ShippingMethodManager;
use Modules\Shipping\Livewire\ShippingZoneManager;
use Tests\TestCase;

class ShippingLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ShippingPermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Livewire Tenant', 'slug' => 'lw-tenant', 'status' => 'active']);
        $this->user = User::create([
            'name' => 'Livewire Admin',
            'email' => 'lw@hyperstore.ch',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenant->id,
            'is_super_admin' => true,
        ]);
        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
    }

    public function test_livewire_shipping_zone_creation(): void
    {
        $this->actingAs($this->user);

        Livewire::test(ShippingZoneManager::class)
            ->set('code', 'LW_ZONE')
            ->set('name', 'Livewire Zone')
            ->set('priority', 5)
            ->call('createZone')
            ->assertStatus(200);

        $this->assertDatabaseHas('shipping_zones', [
            'tenant_id' => $this->tenant->id,
            'code' => 'LW_ZONE',
        ]);
    }

    public function test_livewire_shipping_method_creation(): void
    {
        $this->actingAs($this->user);

        Livewire::test(ShippingMethodManager::class)
            ->set('code', 'LW_METHOD')
            ->set('name', 'Livewire Method')
            ->set('rateCalculatorType', 'flat_rate')
            ->set('baseAmount', 750)
            ->call('createMethod')
            ->assertStatus(200);

        $this->assertDatabaseHas('shipping_methods', [
            'tenant_id' => $this->tenant->id,
            'code' => 'LW_METHOD',
            'base_amount' => 750,
        ]);
    }
}
