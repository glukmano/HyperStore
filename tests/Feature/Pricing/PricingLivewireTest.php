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
use Modules\Pricing\Models\ExchangeRate;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Pricing\Models\TaxZone;

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

test('price book manager edits and archives a price book', function (): void {
    $pb = PriceBook::create([
        'tenant_id' => $this->tenant->id, 'name' => 'EU Wholesale', 'code' => 'eu-wholesale', 'currency' => 'EUR',
    ]);

    Livewire::test(PriceBookManager::class)
        ->call('editPriceBook', $pb->id)
        ->set('editName', 'EU Wholesale v2')
        ->set('editPriority', 5)
        ->call('updatePriceBook')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('price_books', ['id' => $pb->id, 'name' => 'EU Wholesale v2', 'priority' => 5]);

    Livewire::test(PriceBookManager::class)
        ->call('openArchiveConfirm', $pb->id)
        ->call('archivePriceBook')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('price_books', ['id' => $pb->id, 'status' => 'archived']);
});

test('unauthorized user cannot edit a price book', function (): void {
    $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'name' => 'Locked PB', 'code' => 'locked-pb', 'currency' => 'USD']);

    $unauthorized = User::create(['name' => 'No Perms PB', 'email' => 'noperm-pb@hyperstore.test', 'password' => bcrypt('password')]);
    $this->actingAs($unauthorized);

    Livewire::test(PriceBookManager::class)
        ->call('editPriceBook', $pb->id)
        ->assertForbidden();
});

test('product pricing manager edits and deactivates a price', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'LW-PRICE-EDIT',
        translations: ['en' => ['name' => 'Priced Edit Prod']],
    ));

    $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'name' => 'Std', 'code' => 'std-edit', 'currency' => 'USD']);

    Livewire::test(ProductPricingManager::class)
        ->set('selectedProductId', $product->id)
        ->set('selectedPriceBookId', $pb->id)
        ->set('amount', '10.00')
        ->call('savePrice');

    $price = Price::where('product_id', $product->id)->firstOrFail();

    Livewire::test(ProductPricingManager::class)
        ->call('editPrice', $price->id)
        ->set('amount', '15.00')
        ->call('savePrice')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('prices', ['id' => $price->id, 'amount_minor' => 1500]);

    Livewire::test(ProductPricingManager::class)
        ->call('openToggleConfirm', $price->id)
        ->call('togglePriceStatus')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('prices', ['id' => $price->id, 'status' => 'inactive']);
});

test('exchange rate manager edits an existing rate via upsert', function (): void {
    Livewire::test(ExchangeRateManager::class)
        ->set('baseCurrency', 'USD')
        ->set('targetCurrency', 'GBP')
        ->set('rate', '0.79000000')
        ->call('setRate');

    $rate = ExchangeRate::where('base_currency', 'USD')->where('target_currency', 'GBP')->firstOrFail();

    Livewire::test(ExchangeRateManager::class)
        ->call('editRate', $rate->id)
        ->set('rate', '0.80000000')
        ->call('setRate')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('exchange_rates', ['id' => $rate->id, 'rate' => '0.80000000']);
});

test('tax manager edits a tax class and a tax zone', function (): void {
    Livewire::test(TaxManager::class)
        ->set('className', 'Standard VAT')
        ->set('classCode', 'standard')
        ->call('createClass')
        ->set('zoneName', 'US Zone')
        ->set('zoneCode', 'us-zone')
        ->set('countryCode', 'US')
        ->call('createZone');

    $class = TaxClass::where('code', 'standard')->firstOrFail();
    $zone = TaxZone::where('code', 'us-zone')->firstOrFail();

    Livewire::test(TaxManager::class)
        ->call('editClass', $class->id)
        ->set('editClassName', 'Standard VAT v2')
        ->call('updateClass')
        ->assertHasNoErrors();

    Livewire::test(TaxManager::class)
        ->call('editZone', $zone->id)
        ->set('editZoneName', 'US Zone v2')
        ->call('updateZone')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('tax_classes', ['id' => $class->id, 'name' => 'Standard VAT v2']);
    $this->assertDatabaseHas('tax_zones', ['id' => $zone->id, 'name' => 'US Zone v2']);
});

test('unauthorized user cannot edit a tax class', function (): void {
    $class = TaxClass::create(['tenant_id' => $this->tenant->id, 'name' => 'Locked', 'code' => 'locked-tax']);

    $unauthorized = User::create(['name' => 'No Perms Tax', 'email' => 'noperm-tax@hyperstore.test', 'password' => bcrypt('password')]);
    $this->actingAs($unauthorized);

    Livewire::test(TaxManager::class)
        ->call('editClass', $class->id)
        ->assertForbidden();
});
