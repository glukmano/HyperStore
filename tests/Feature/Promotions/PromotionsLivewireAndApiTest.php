<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;
use Modules\Promotions\Livewire\CouponManager;
use Modules\Promotions\Livewire\PromotionManager;
use Modules\Promotions\Models\Promotion;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(PricingPermissionSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'promo-lw-tenant'],
        ['name' => 'Promo Livewire Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'promo-admin@hyperstore.test'],
        ['name' => 'Promo Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );

    $this->actingAs($this->admin);
});

test('PromotionManager creates promotions via Livewire', function (): void {
    Livewire::test(PromotionManager::class)
        ->set('name', 'Black Friday Super Sale')
        ->set('code', 'bf-super-sale')
        ->set('priority', 99)
        ->set('is_exclusive', true)
        ->call('createPromotion')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('promotions', [
        'code' => 'bf-super-sale',
        'is_exclusive' => true,
    ]);
});

test('CouponManager generates case-normalized coupons via Livewire', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Coupon Target Promo',
        'code' => 'target-promo',
    ]);

    Livewire::test(CouponManager::class)
        ->set('promotionId', $promo->id)
        ->set('code', 'welcome2026')
        ->set('usageLimit', 500)
        ->call('createCoupon')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('coupons', [
        'code' => 'WELCOME2026',
        'usage_limit' => 500,
    ]);
});

test('Promotions API evaluates cart discounts via POST /api/v1/promotions/evaluate', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'API Test Promo 15%',
        'code' => 'API-15-OFF',
        'priority' => 10,
        'status' => 'active',
    ]);

    $promo->actions()->create([
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 15],
    ]);

    $response = $this->postJson('/api/v1/promotions/evaluate', [
        'currency' => 'USD',
        'items' => [
            ['product_id' => 100, 'quantity' => 2, 'unit_price_minor' => 5000],
        ],
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(200)
        ->assertJsonPath('data.subtotal_minor', 10000)
        ->assertJsonPath('data.total_discount_minor', 1500)
        ->assertJsonPath('data.final_total_minor', 8500);
});
