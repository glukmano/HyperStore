<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Catalog\DTOs\StorePublicationData;
use Modules\Catalog\Events\ProductPublishedToStore;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Catalog\Models\ProductStoreListingTranslation;

class PublishProductToStoreAction
{
    public function __construct(
        private readonly ?AuditManagerInterface $auditManager = null,
    ) {}

    public function execute(StorePublicationData $data): ProductStoreListing
    {
        return DB::transaction(function () use ($data): ProductStoreListing {
            /** @var Product $product */
            $product = Product::findOrFail($data->productId);

            /** @var Store $store */
            $store = Store::findOrFail($data->storeId);

            // Cross-Tenant Validation: Product and Store must belong to same tenant!
            if ($product->tenant_id !== $store->tenant_id) {
                throw new InvalidArgumentException("Cross-tenant violation: Product [{$product->id}] and Store [{$store->id}] belong to different tenants.");
            }

            // Cross-Tenant Validation for Markets:
            if (! empty($data->marketIds)) {
                $invalidMarket = Market::query()
                    ->whereIn('id', $data->marketIds)
                    ->whereNotNull('tenant_id')
                    ->where('tenant_id', '!=', $product->tenant_id)
                    ->exists();

                if ($invalidMarket) {
                    throw new InvalidArgumentException("Cross-tenant violation: One or more selected markets do not belong to tenant [{$product->tenant_id}].");
                }
            }

            /** @var ProductStoreListing $listing */
            $listing = ProductStoreListing::updateOrCreate(
                [
                    'product_id' => $data->productId,
                    'store_id' => $data->storeId,
                ],
                [
                    'status' => $data->status,
                    'visibility' => $data->visibility,
                    'is_featured' => $data->isFeatured,
                    'sort_order' => $data->sortOrder,
                    'published_at' => $data->publishedAt ?? now(),
                ]
            );

            foreach ($data->translations as $locale => $translation) {
                ProductStoreListingTranslation::updateOrCreate(
                    [
                        'product_store_listing_id' => $listing->id,
                        'locale' => $locale,
                    ],
                    [
                        'slug' => $translation['slug'],
                        'name' => $translation['name'] ?? null,
                        'short_description' => $translation['short_description'] ?? null,
                        'description' => $translation['description'] ?? null,
                    ]
                );
            }

            if (! empty($data->marketIds)) {
                $marketSync = [];
                foreach ($data->marketIds as $mId) {
                    $marketSync[$mId] = ['is_enabled' => true];
                }
                $listing->markets()->sync($marketSync);
            }

            if (! empty($data->channelIds)) {
                $channelSync = [];
                foreach ($data->channelIds as $cId) {
                    $channelSync[$cId] = ['is_enabled' => true];
                }
                $listing->channels()->sync($channelSync);
            }

            $this->auditManager?->log(
                event: 'product.published',
                subject: $listing,
                properties: ['status' => $data->status, 'store_id' => $data->storeId]
            );

            ProductPublishedToStore::dispatch($listing);

            return $listing->load(['translations', 'markets', 'channels', 'store']);
        });
    }
}
