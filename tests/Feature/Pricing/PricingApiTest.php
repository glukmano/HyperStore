<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Pricing\Models\ExchangeRate;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Pricing\Models\TaxRate;
use Modules\Pricing\Models\TaxZone;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(PricingPermissionSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'pricing-api-tenant'],
        ['name' => 'Pricing API Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'api-admin@hyperstore.test'],
        ['name' => 'API Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );

    $this->actingAs($this->admin);
});

test('api can create and list price books', function (): void {
    $response = $this->postJson('/api/v1/pricing/price-books', [
        'name' => 'Euro Market Catalog',
        'code' => 'eur-catalog',
        'currency' => 'EUR',
        'priority' => 5,
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(201)
        ->assertJsonPath('data.code', 'eur-catalog');

    $listResponse = $this->getJson('/api/v1/pricing/price-books', ['X-Tenant-ID' => (string) $this->tenant->id]);
    $listResponse->assertStatus(200);
});

test('api resolves pricing via POST /api/v1/pricing/resolve', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'RESOLVE-API-SKU',
        translations: ['en' => ['name' => 'Resolve API Product']],
    ));

    $pb = PriceBook::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Default USD',
        'code' => 'def-usd',
        'currency' => 'USD',
        'is_default' => true,
    ]);

    Price::create([
        'tenant_id' => $this->tenant->id,
        'price_book_id' => $pb->id,
        'product_id' => $product->id,
        'amount_minor' => 12900, // $129.00
        'compare_at_minor' => 14900,
        'currency' => 'USD',
    ]);

    $response = $this->postJson('/api/v1/pricing/resolve', [
        'product_id' => $product->id,
        'currency' => 'USD',
        'quantity' => 1,
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(200)
        ->assertJsonPath('data.unit_price_minor', 12900)
        ->assertJsonPath('data.compare_at_minor', 14900);
});

test('api converts currency via POST /api/v1/pricing/convert-currency', function (): void {
    ExchangeRate::create([
        'tenant_id' => $this->tenant->id,
        'base_currency' => 'USD',
        'target_currency' => 'EUR',
        'rate' => '0.92000000',
    ]);

    $response = $this->postJson('/api/v1/pricing/convert-currency', [
        'amount_minor' => 10000,
        'source_currency' => 'USD',
        'target_currency' => 'EUR',
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(200)
        ->assertJsonPath('data.converted_amount_minor', 9200)
        ->assertJsonPath('data.converted_currency', 'EUR');
});

test('api calculates tax via POST /api/v1/pricing/tax-calculate', function (): void {
    $taxClass = TaxClass::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Standard VAT',
        'code' => 'standard-tax',
    ]);

    $taxZone = TaxZone::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'US Zone',
        'code' => 'us-zone',
        'country_code' => 'US',
    ]);

    TaxRate::create([
        'tenant_id' => $this->tenant->id,
        'tax_class_id' => $taxClass->id,
        'tax_zone_id' => $taxZone->id,
        'name' => 'US State Tax 10%',
        'rate_percentage' => '10.0000',
    ]);

    $response = $this->postJson('/api/v1/pricing/tax-calculate', [
        'amount_minor' => 10000, // $100.00
        'currency' => 'USD',
        'tax_class_id' => $taxClass->id,
        'country_code' => 'US',
        'is_tax_inclusive' => false,
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(200)
        ->assertJsonPath('data.net_minor', 10000)
        ->assertJsonPath('data.tax_minor', 1000)
        ->assertJsonPath('data.gross_minor', 11000);
});
