<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\DTOs\StorePublicationData;
use Modules\Catalog\Events\ProductPublishedToStore;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Catalog\Models\ProductStoreListingTranslation;

class PublishProductToStoreAction
{
    public function execute(StorePublicationData $data): ProductStoreListing
    {
        return DB::transaction(function () use ($data): ProductStoreListing {
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

            ProductPublishedToStore::dispatch($listing);

            return $listing->load(['translations', 'markets', 'channels', 'store']);
        });
    }
}
