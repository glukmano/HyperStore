<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\CategoryTranslation;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Catalog\Models\ProductTranslation;
use Modules\Catalog\Models\ProductVariant;
use Modules\DigitalDelivery\Models\DigitalAsset;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;

/**
 * Pre-Production Readiness — a compact, curated catalog covering
 * representative ProductTypes with real prices, inventory, and
 * translations. Not a bulk Faker dump — every row is meaningful for a
 * human clicking through the demo.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->firstOrFail();
        $store = Store::where('tenant_id', $tenant->id)->where('slug', 'demo-flagship')->firstOrFail();
        $source = InventorySource::where('tenant_id', $tenant->id)->where('code', 'DEMO_SRC')->firstOrFail();

        TaxClass::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_STD_TAX'],
            ['name' => 'Standard Tax', 'is_default' => true]
        );

        $priceBook = PriceBook::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_PB'],
            ['name' => 'Demo Price Book', 'currency' => 'USD', 'status' => 'active', 'priority' => 1]
        );

        $zone = ShippingZone::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'DEMO_ZONE'], ['name' => 'United States', 'status' => 'active']);
        ShippingZoneRule::firstOrCreate(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'US']);
        $method = ShippingMethod::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_STANDARD'],
            ['name' => 'Standard Shipping', 'rate_calculator_type' => 'flat_rate', 'currency' => 'USD', 'base_amount' => 500, 'status' => 'active']
        );
        ShippingMethodZone::firstOrCreate(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);
        $pickupMethod = ShippingMethod::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_PICKUP_RATE'],
            ['name' => 'In-Store Pickup', 'rate_calculator_type' => 'flat_rate', 'currency' => 'USD', 'base_amount' => 0, 'status' => 'active']
        );
        ShippingMethodZone::firstOrCreate(['shipping_method_id' => $pickupMethod->id, 'shipping_zone_id' => $zone->id]);

        $category = Category::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'demo-electronics'],
            ['status' => 'active']
        );
        CategoryTranslation::firstOrCreate(
            ['category_id' => $category->id, 'locale' => 'en'],
            ['name' => 'Electronics', 'slug' => 'demo-electronics']
        );

        $this->makeSimpleProduct($tenant->id, $store->id, $source->id, $priceBook->id, $category->id, 'physical', 'DEMO-PHYS-001', 'Demo Wireless Headphones', 4999, 50);
        $variantParent = $this->makeVariantProduct($tenant->id, $store->id, $source->id, $priceBook->id, $category->id);
        $this->makeSimpleProduct($tenant->id, $store->id, $source->id, $priceBook->id, $category->id, 'digital', 'DEMO-DIGI-001', 'Demo E-Book: Getting Started', 1999, null, digitalAsset: true);
        $this->makeSimpleProduct($tenant->id, $store->id, $source->id, $priceBook->id, $category->id, 'license', 'DEMO-LIC-001', 'Demo Software License Key', 9999, null);
        $this->makeSimpleProduct($tenant->id, $store->id, $source->id, $priceBook->id, $category->id, 'gift_card', 'DEMO-GC-001', 'Demo Gift Card', 5000, null);
        $this->makeSimpleProduct($tenant->id, $store->id, $source->id, $priceBook->id, $category->id, 'subscription', 'DEMO-SUB-001', 'Demo Monthly Streaming Plan', 1299, null);

        $this->command?->info('Demo catalog seeded: 6 products (physical, physical+variant, digital, license, gift_card, subscription).');
    }

    private function makeSimpleProduct(
        int $tenantId,
        int $storeId,
        int $sourceId,
        int $priceBookId,
        int $categoryId,
        string $type,
        string $sku,
        string $name,
        int $priceMinor,
        ?int $stockQty,
        bool $digitalAsset = false,
    ): Product {
        $product = Product::firstOrCreate(
            ['tenant_id' => $tenantId, 'sku' => $sku],
            ['name' => $name, 'slug' => Str::slug($name), 'product_type' => $type, 'status' => 'active', 'barcode' => '0'.random_int(100000000000, 999999999999)]
        );

        ProductTranslation::firstOrCreate(
            ['product_id' => $product->id, 'locale' => 'en'],
            ['name' => $name, 'short_description' => 'A representative demo '.$type.' product.', 'description' => 'This is demo data seeded for local exploration of the '.$type.' ProductType.']
        );

        DB::table('product_categories')->updateOrInsert(
            ['product_id' => $product->id, 'category_id' => $categoryId],
            ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        ProductStoreListing::firstOrCreate(['product_id' => $product->id, 'store_id' => $storeId], ['status' => 'published']);
        Price::firstOrCreate(
            ['tenant_id' => $tenantId, 'price_book_id' => $priceBookId, 'product_id' => $product->id],
            ['amount_minor' => $priceMinor, 'currency' => 'USD', 'status' => 'active']
        );

        if ($stockQty !== null) {
            StockItem::firstOrCreate(
                ['tenant_id' => $tenantId, 'inventory_source_id' => $sourceId, 'product_id' => $product->id],
                ['on_hand' => $stockQty, 'reserved' => 0]
            );
        }

        if ($digitalAsset) {
            DigitalAsset::firstOrCreate(
                ['tenant_id' => $tenantId, 'product_id' => $product->id, 'version' => 1],
                ['disk' => 'local', 'path' => 'demo/getting-started.pdf', 'checksum' => hash('sha256', 'demo-asset'), 'max_downloads' => 5, 'download_expiry_days' => null]
            );
        }

        return $product;
    }

    private function makeVariantProduct(int $tenantId, int $storeId, int $sourceId, int $priceBookId, int $categoryId): Product
    {
        $product = Product::firstOrCreate(
            ['tenant_id' => $tenantId, 'sku' => 'DEMO-VAR-001'],
            ['name' => 'Demo T-Shirt', 'slug' => 'demo-t-shirt', 'product_type' => 'variable', 'status' => 'active', 'barcode' => '0'.random_int(100000000000, 999999999999)]
        );

        ProductTranslation::firstOrCreate(
            ['product_id' => $product->id, 'locale' => 'en'],
            ['name' => 'Demo T-Shirt', 'short_description' => 'A demo variant product with size options.', 'description' => 'Demo data — a variable ProductType with two variants.']
        );
        DB::table('product_categories')->updateOrInsert(
            ['product_id' => $product->id, 'category_id' => $categoryId],
            ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        ProductStoreListing::firstOrCreate(['product_id' => $product->id, 'store_id' => $storeId], ['status' => 'published']);

        foreach ([['S', 1999], ['M', 1999]] as [$size, $priceMinor]) {
            $variant = ProductVariant::firstOrCreate(
                ['product_id' => $product->id, 'combination_hash' => hash('sha256', $size)],
                ['sku' => "DEMO-VAR-001-{$size}", 'barcode' => '0'.random_int(100000000000, 999999999999), 'status' => 'active']
            );
            Price::firstOrCreate(
                ['tenant_id' => $tenantId, 'price_book_id' => $priceBookId, 'product_id' => $product->id, 'product_variant_id' => $variant->id],
                ['amount_minor' => $priceMinor, 'currency' => 'USD', 'status' => 'active']
            );
            StockItem::firstOrCreate(
                ['tenant_id' => $tenantId, 'inventory_source_id' => $sourceId, 'product_id' => $product->id, 'product_variant_id' => $variant->id],
                ['on_hand' => 25, 'reserved' => 0]
            );
        }

        return $product;
    }
}
