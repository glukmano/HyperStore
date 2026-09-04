<?php

declare(strict_types=1);

namespace Modules\Cms\Services;

use App\Core\Support\ReservedSlugs;
use Modules\Cms\Exceptions\ReservedSlugException;

/**
 * Extends the same reserved-slug list Modules\Marketplace\ValueObjects\VendorSlug
 * already enforced for vendor storefront slugs (App\Core\Support\ReservedSlugs)
 * — one shared list, not two independently-drifting ones. Prevents a CMS
 * page from ever claiming a route like /search that a future Search
 * storefront page needs.
 */
final class CmsSlugValidator
{
    public function assertAllowed(string $slug): void
    {
        $normalized = strtolower(trim($slug, '/'));

        if (in_array($normalized, ReservedSlugs::LIST, true)) {
            throw new ReservedSlugException("The slug \"{$slug}\" is a reserved platform keyword and cannot be used for a CMS page.");
        }
    }
}
