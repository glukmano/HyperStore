<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ChannelSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Actions\PublishProductToStoreAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\DTOs\StorePublicationData;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(ChannelSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'multistore-tenant'],
        ['name' => 'MultiStore Tenant', 'status' => 'active']
    );

    $this->store1 = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'US Flagship Store',
        'slug' => 'us-store',
        'status' => 'active',
    ]);

    $this->store2 = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Saudi Store',
        'slug' => 'sa-store',
        'status' => 'active',
    ]);

    $this->marketUS = Market::firstOrCreate(
        ['tenant_id' => $this->tenant->id, 'code' => 'US'],
        ['name' => 'United States', 'default_currency_code' => 'USD', 'default_locale_code' => 'en']
    );

    $this->marketSA = Market::firstOrCreate(
        ['tenant_id' => $this->tenant->id, 'code' => 'SA'],
        ['name' => 'Saudi Arabia', 'default_currency_code' => 'SAR', 'default_locale_code' => 'ar']
    );

    $this->webChannel = Channel::firstOrCreate(['handle' => 'website'], ['name' => 'Web', 'type' => 'website']);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'GLOBAL-SMARTPHONE-PRO',
        translations: [
            'en' => ['name' => 'SmartPhone Pro'],
            'ar' => ['name' => 'هاتف ذكي برو'],
        ],
    ));
});

test('canonical product can be published to multiple stores with store-specific localized slugs', function (): void {
    $action = app(PublishProductToStoreAction::class);

    // Publish to US Store
    $listingUS = $action->execute(new StorePublicationData(
        productId: $this->product->id,
        storeId: $this->store1->id,
        status: 'published',
        translations: [
            'en' => ['slug' => 'smartphone-pro-us', 'name' => 'SmartPhone Pro (US Edition)'],
        ],
        marketIds: [$this->marketUS->id],
        channelIds: [$this->webChannel->id],
    ));

    // Publish to Saudi Store
    $listingSA = $action->execute(new StorePublicationData(
        productId: $this->product->id,
        storeId: $this->store2->id,
        status: 'published',
        translations: [
            'ar' => ['slug' => 'smartphone-pro-sa', 'name' => 'هاتف ذكي برو (نسخة السعودية)'],
            'en' => ['slug' => 'smartphone-pro-sa-en', 'name' => 'SmartPhone Pro KSA'],
        ],
        marketIds: [$this->marketSA->id],
        channelIds: [$this->webChannel->id],
    ));

    expect($listingUS->status)->toBe('published')
        ->and($listingUS->store_id)->toBe($this->store1->id)
        ->and($listingUS->translations)->toHaveCount(1)
        ->and($listingUS->translations->first()->slug)->toBe('smartphone-pro-us')
        ->and($listingSA->status)->toBe('published')
        ->and($listingSA->store_id)->toBe($this->store2->id)
        ->and($listingSA->translations)->toHaveCount(2);

    $this->assertDatabaseHas('product_store_markets', [
        'product_store_listing_id' => $listingUS->id,
        'market_id' => $this->marketUS->id,
    ]);

    $this->assertDatabaseHas('product_store_markets', [
        'product_store_listing_id' => $listingSA->id,
        'market_id' => $this->marketSA->id,
    ]);
});
