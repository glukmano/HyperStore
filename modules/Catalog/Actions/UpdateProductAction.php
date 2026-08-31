<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Events\ProductUpdated;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductTranslation;

class UpdateProductAction
{
    public function execute(Product $product, ProductData $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $product->update([
                'brand_id' => $data->brandId,
                'attribute_set_id' => $data->attributeSetId,
                'product_type' => $data->productType,
                'sku' => $data->sku,
                'barcode' => $data->barcode,
                'mpn' => $data->mpn,
                'status' => $data->status,
                'metadata' => $data->metadata,
            ]);

            foreach ($data->translations as $locale => $translation) {
                ProductTranslation::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'locale' => $locale,
                    ],
                    [
                        'name' => $translation['name'] ?? $product->sku,
                        'short_description' => $translation['short_description'] ?? null,
                        'description' => $translation['description'] ?? null,
                    ]
                );
            }

            if (! empty($data->categoryIds)) {
                $syncData = [];
                foreach ($data->categoryIds as $catId) {
                    $syncData[$catId] = ['is_primary' => ($catId === $data->primaryCategoryId)];
                }
                $product->categories()->sync($syncData);
            }

            ProductUpdated::dispatch($product);

            return $product->load(['translations', 'categories', 'brand', 'attributeSet']);
        });
    }
}
