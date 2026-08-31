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
use Modules\Shipping\Livewire\CarrierManager;
use Modules\Shipping\Livewire\ShippingMethodManager;
use Modules\Shipping\Livewire\ShippingZoneManager;
use Tests\TestCase;

class LivewireAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ShippingPermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Auth Tenant', 'slug' => 'auth-tenant', 'status' => 'active']);
        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));

        $this->unauthorizedUser = User::create([
            'name' => 'Unauthorized User',
            'email' => 'unauth@hyperstore.ch',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_unauthorized_user_cannot_create_shipping_zone(): void
    {
        $this->actingAs($this->unauthorizedUser);

        Livewire::test(ShippingZoneManager::class)
            ->set('code', 'UNAUTH_ZONE')
            ->set('name', 'Unauthorized Zone')
            ->call('createZone')
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_shipping_method(): void
    {
        $this->actingAs($this->unauthorizedUser);

        Livewire::test(ShippingMethodManager::class)
            ->set('code', 'UNAUTH_METHOD')
            ->set('name', 'Unauthorized Method')
            ->set('rateCalculatorType', 'flat_rate')
            ->set('currency', 'CHF')
            ->set('baseAmount', 1000)
            ->call('createMethod')
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_carrier(): void
    {
        $this->actingAs($this->unauthorizedUser);

        Livewire::test(CarrierManager::class)
            ->set('code', 'UNAUTH_CARRIER')
            ->set('name', 'Unauthorized Carrier')
            ->set('providerCode', 'manual')
            ->call('createCarrier')
            ->assertForbidden();
    }
}
