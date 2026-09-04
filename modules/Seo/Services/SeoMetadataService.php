<?php

declare(strict_types=1);

namespace Modules\Seo\Services;

use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Cms\Models\BlogPost;
use Modules\Cms\Models\Page;
use Modules\Seo\DTOs\SeoMetadata;
use Modules\Seo\Exceptions\SubjectNotVisibleException;
use Modules\Seo\StructuredData\ArticleSchemaBuilder;
use Modules\Seo\StructuredData\ProductSchemaBuilder;

/**
 * One central SEO service — never page-specific duplicated <meta> logic.
 * Every resolution path re-checks the SAME authoritative visibility/status
 * fields the rest of the platform already uses (ProductStoreListing.status,
 * Page.status, BlogPost.status) — never a parallel visibility concept, and
 * never metadata for unpublished/draft/inactive content.
 */
final class SeoMetadataService
{
    public function __construct(
        private readonly ProductSchemaBuilder $productSchemaBuilder,
        private readonly ArticleSchemaBuilder $articleSchemaBuilder,
    ) {}

    public function forProductAtStore(Product $product, int $storeId, string $canonicalUrl): SeoMetadata
    {
        $listing = ProductStoreListing::query()
            ->where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->first();

        if ($listing === null || $listing->status !== 'published' || $listing->visibility !== 'visible') {
            throw new SubjectNotVisibleException("Product [{$product->id}] is not published/visible at store [{$storeId}].");
        }

        $translation = $product->translation();

        return new SeoMetadata(
            title: $translation !== null ? $translation->name : $product->name,
            description: $translation?->short_description,
            canonicalUrl: $canonicalUrl,
            jsonLd: $this->productSchemaBuilder->build($product),
        );
    }

    public function forPage(Page $page, string $canonicalUrl, string $locale): SeoMetadata
    {
        if (! $page->isPublished()) {
            throw new SubjectNotVisibleException("Page [{$page->id}] is not published.");
        }

        $translation = $page->translation($locale);
        $title = '';
        if ($translation !== null) {
            $title = $translation->meta_title !== null ? $translation->meta_title : $translation->title;
        }

        return new SeoMetadata(
            title: $title,
            description: $translation?->meta_description,
            canonicalUrl: $canonicalUrl,
        );
    }

    public function forBlogPost(BlogPost $post, string $canonicalUrl, string $locale): SeoMetadata
    {
        if (! $post->isPublished()) {
            throw new SubjectNotVisibleException("Blog post [{$post->id}] is not published.");
        }

        $translation = $post->translation($locale);
        $title = '';
        $description = null;
        if ($translation !== null) {
            $title = $translation->meta_title !== null ? $translation->meta_title : $translation->title;
            $description = $translation->meta_description !== null ? $translation->meta_description : $translation->excerpt;
        }

        return new SeoMetadata(
            title: $title,
            description: $description,
            canonicalUrl: $canonicalUrl,
            jsonLd: $this->articleSchemaBuilder->build($post),
        );
    }

    /**
     * Real-but-partial hreflang seam (Owner Delta §23): produces alternate
     * locale URLs using only config('app.supported_locales'), which exist
     * today — no fabricated Phase-18 Market/domain semantics. x-default
     * points at the fallback locale. Full Market-domain/Vendor-domain
     * hreflang expansion is a documented Phase-18 extension, not built here.
     *
     * @return array<string, string>
     */
    public function resolveAlternateLocaleUrls(callable $urlForLocale): array
    {
        $locales = (array) config('app.supported_locales', ['en']);
        $urls = [];

        foreach ($locales as $locale) {
            $urls[$locale] = $urlForLocale($locale);
        }

        $urls['x-default'] = $urlForLocale(config('app.fallback_locale', 'en'));

        return $urls;
    }
}
