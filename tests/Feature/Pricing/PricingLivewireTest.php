<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Pricing\Livewire\ExchangeRateManager;
use Modules\Pricing\Livewire\PriceBookManager;
use Modules\Pricing\Livewire\ProductPricingManager;
use Modules\Pricing\Livewire\TaxManager;
use Modules\Pricing\Models\PriceBook;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(PricingPermissionSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'pricing-lw-tenant'],
        ['name' => 'Pricing Livewire Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'pricing-admin@hyperstore.test'],
        ['name' => 'Pricing Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );

    $this->actingAs($this->admin);
});

test('PriceBookManager creates price books', function (): void {
    Livewire::test(PriceBookManager::class)
        ->set('name', 'B2B Wholesale USD')
        ->set('code', 'b2b-usd')
        ->set('currency', 'USD')
        ->set('priority', 20)
        ->call('createPriceBook')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('price_books', ['code' => 'b2b-usd', 'currency' => 'USD']);
});

test('ProductPricingManager assigns product price in price book', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'LW-PRICE-PROD',
        translations: ['en' => ['name' => 'LW Priced Prod']],
    ));

    $pb = PriceBook::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Standard USD',
        'code' => 'std-usd',
        'currency' => 'USD',
    ]);

    Livewire::test(ProductPricingManager::class)
        ->set('selectedProductId', $product->id)
        ->set('selectedPriceBookId', $pb->id)
        ->set('amount', '49.99')
        ->set('compareAt', '59.99')
        ->call('savePrice')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('prices', [
        'product_id' => $product->id,
        'amount_minor' => 4999,
        'compare_at_minor' => 5999,
    ]);
});

test('ExchangeRateManager updates currency exchange rates', function (): void {
    Livewire::test(ExchangeRateManager::class)
        ->set('baseCurrency', 'USD')
        ->set('targetCurrency', 'CHF')
        ->set('rate', '0.88500000')
        ->call('setRate')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('exchange_rates', [
        'base_currency' => 'USD',
        'target_currency' => 'CHF',
        'rate' => '0.88500000',
    ]);
});

test('TaxManager creates tax classes and zones', function (): void {
    Livewire::test(TaxManager::class)
        ->set('className', 'Reduced VAT')
        ->set('classCode', 'reduced')
        ->call('createClass')
        ->set('zoneName', 'Swiss Zone')
        ->set('zoneCode', 'ch-zone')
        ->set('countryCode', 'CH')
        ->call('createZone')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tax_classes', ['code' => 'reduced']);
    $this->assertDatabaseHas('tax_zones', ['code' => 'ch-zone', 'country_code' => 'CH']);
});
