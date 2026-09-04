<?php

declare(strict_types=1);

namespace Modules\Seo\Services;

use App\Core\Stores\Models\Store;

final class RobotsService
{
    public function generate(Store $store, string $sitemapUrl): string
    {
        $blockAll = (bool) ($store->settings['block_search_engines'] ?? false);

        if ($blockAll) {
            return "User-agent: *\nDisallow: /\n";
        }

        return "User-agent: *\nDisallow: /control-center\nDisallow: /cart\nDisallow: /checkout\n\nSitemap: {$sitemapUrl}\n";
    }
}
