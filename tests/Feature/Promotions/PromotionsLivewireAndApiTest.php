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

test('PromotionManager edits and deactivates a promotion via the existing status field', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Spring Sale', 'code' => 'spring-sale', 'priority' => 1, 'status' => 'active',
    ]);

    Livewire::test(PromotionManager::class)
        ->call('editPromotion', $promo->id)
        ->set('editName', 'Spring Mega Sale')
        ->set('editPriority', 10)
        ->call('updatePromotion')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('promotions', ['id' => $promo->id, 'name' => 'Spring Mega Sale', 'priority' => 10]);

    Livewire::test(PromotionManager::class)
        ->call('toggleStatus', $promo->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('promotions', ['id' => $promo->id, 'status' => 'inactive']);
});

test('unauthorized user cannot edit a promotion', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Locked Promo', 'code' => 'locked-promo', 'priority' => 1, 'status' => 'active',
    ]);

    $unauthorized = User::create(['name' => 'No Perms Promo', 'email' => 'noperm-promo@hyperstore.test', 'password' => bcrypt('password')]);
    $this->actingAs($unauthorized);

    Livewire::test(PromotionManager::class)
        ->call('editPromotion', $promo->id)
        ->assertForbidden();
});

test('CouponManager edits and deactivates a coupon via the existing status field', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Coupon Promo', 'code' => 'coupon-promo', 'priority' => 1, 'status' => 'active',
    ]);
    $coupon = $promo->coupons()->create([
        'tenant_id' => $this->tenant->id, 'code' => 'SAVE10', 'status' => 'active',
    ]);

    Livewire::test(CouponManager::class)
        ->call('editCoupon', $coupon->id)
        ->set('editCode', 'SAVE15')
        ->set('editUsageLimit', 100)
        ->call('updateCoupon')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'code' => 'SAVE15', 'usage_limit' => 100]);

    Livewire::test(CouponManager::class)
        ->call('toggleStatus', $coupon->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'status' => 'inactive']);
});

test('unauthorized user cannot edit a coupon', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Coupon Promo 2', 'code' => 'coupon-promo-2', 'priority' => 1, 'status' => 'active',
    ]);
    $coupon = $promo->coupons()->create(['tenant_id' => $this->tenant->id, 'code' => 'LOCKED10', 'status' => 'active']);

    $unauthorized = User::create(['name' => 'No Perms Coupon', 'email' => 'noperm-coupon@hyperstore.test', 'password' => bcrypt('password')]);
    $this->actingAs($unauthorized);

    Livewire::test(CouponManager::class)
        ->call('editCoupon', $coupon->id)
        ->assertForbidden();
});
