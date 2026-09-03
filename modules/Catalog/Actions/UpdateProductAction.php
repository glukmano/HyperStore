<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\SuperAdmin\Contracts\TenantResourceEntitlementGuardInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Events\ProductUpdated;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductTranslation;

class UpdateProductAction
{
    public function __construct(
        private readonly ?AuditManagerInterface $auditManager = null,
    ) {}

    public function execute(Product $product, ProductData $data): Product
    {
        $isUnarchive = ($product->status === 'archived' && $data->status !== 'archived');

        $mutation = function () use ($product, $data): Product {
            // Cross-tenant validation for Brand
            if ($data->brandId !== null) {
                $brand = Brand::find($data->brandId);
                if ($brand !== null && $brand->tenant_id !== $product->tenant_id) {
                    throw new InvalidArgumentException("Cross-tenant violation: Brand [{$data->brandId}] belongs to a different tenant.");
                }
            }

            // Cross-tenant validation for Categories
            if (! empty($data->categoryIds)) {
                $invalidCat = Category::query()
                    ->whereIn('id', $data->categoryIds)
                    ->where('tenant_id', '!=', $product->tenant_id)
                    ->exists();

                if ($invalidCat) {
                    throw new InvalidArgumentException('Cross-tenant violation: One or more categories belong to a different tenant.');
                }
            }

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

            $this->auditManager?->log(
                event: 'product.updated',
                subject: $product,
                properties: ['sku' => $product->sku]
            );

            ProductUpdated::dispatch($product);

            return $product->load(['translations', 'categories', 'brand', 'attributeSet']);
        };

        if ($isUnarchive && app()->bound(TenantResourceEntitlementGuardInterface::class)) {
            return app(TenantResourceEntitlementGuardInterface::class)->admit($product->tenant_id, 'max_products', $mutation);
        }

        return DB::transaction($mutation);
    }
}
