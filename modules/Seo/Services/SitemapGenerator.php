<?php

declare(strict_types=1);

namespace Modules\Seo\Services;

use App\Core\Stores\Models\Store;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Cms\Models\BlogPost;
use Modules\Cms\Models\Page;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Models\Vendor;

/**
 * Only ever includes published/active content — the same authoritative
 * status checks SeoMetadataService uses, never a parallel visibility
 * concept. Runs as a queued job (SitemapGenerationJob), never
 * synchronously within a customer-facing request.
 */
final class SitemapGenerator
{
    /**
     * @return list<array{loc: string, lastmod: ?string}>
     */
    public function buildEntriesForStore(Store $store): array
    {
        $entries = [];

        ProductStoreListing::query()
            ->where('store_id', $store->id)
            ->where('status', 'published')
            ->where('visibility', 'visible')
            ->with('product')
            ->chunk(500, function ($listings) use (&$entries): void {
                foreach ($listings as $listing) {
                    if ($listing->product === null) {
                        continue;
                    }
                    $entries[] = ['loc' => '/p/'.$listing->product->sku, 'lastmod' => $listing->updated_at?->toIso8601String()];
                }
            });

        Page::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('status', Page::STATUS_PUBLISHED)
            ->with('translations')
            ->chunk(500, function ($pages) use (&$entries): void {
                foreach ($pages as $page) {
                    $translation = $page->translation();
                    if ($translation !== null) {
                        $entries[] = ['loc' => '/'.$translation->slug, 'lastmod' => $page->updated_at?->toIso8601String()];
                    }
                }
            });

        BlogPost::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('status', BlogPost::STATUS_PUBLISHED)
            ->with('translations')
            ->chunk(500, function ($posts) use (&$entries): void {
                foreach ($posts as $post) {
                    $translation = $post->translation();
                    if ($translation !== null) {
                        $entries[] = ['loc' => '/blog/'.$translation->slug, 'lastmod' => $post->updated_at?->toIso8601String()];
                    }
                }
            });

        Vendor::query()
            ->where('tenant_id', $store->tenant_id)
            ->where('operational_status', VendorOperationalStatus::Active->value)
            ->chunk(500, function ($vendors) use (&$entries): void {
                foreach ($vendors as $vendor) {
                    $entries[] = ['loc' => '/vendor/'.$vendor->platform_slug, 'lastmod' => $vendor->updated_at?->toIso8601String()];
                }
            });

        return $entries;
    }

    public function generateAndStore(Store $store): string
    {
        $entries = $this->buildEntriesForStore($store);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($entries as $entry) {
            $xml .= '<url><loc>'.e($entry['loc']).'</loc>';
            if ($entry['lastmod'] !== null) {
                $xml .= '<lastmod>'.e($entry['lastmod']).'</lastmod>';
            }
            $xml .= '</url>'."\n";
        }
        $xml .= '</urlset>';

        $path = "sitemaps/store-{$store->id}.xml";
        Storage::disk('local')->put($path, $xml);

        return $path;
    }
}
