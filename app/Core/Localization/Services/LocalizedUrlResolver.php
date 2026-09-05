<?php

declare(strict_types=1);

namespace App\Core\Localization\Services;

use App\Core\Markets\Models\Market;

/**
 * Phase-18 Owner Delta §7: every publicly-indexable Store+Market+Locale
 * resource needs a real, distinct, crawlable URL — a cookie/session/
 * Accept-Language-chosen Locale is never sufficient as canonical URL
 * identity. When the resolved Market only carries one active Locale, the
 * hostname itself already disambiguates Locale and the bare (unprefixed)
 * route is the canonical URL. When a Market carries more than one active
 * Locale on the same hostname, the `{locale}` path-prefix route
 * (registered alongside every bare storefront route in routes/web.php)
 * is the canonical URL for that Locale.
 */
final class LocalizedUrlResolver
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function resolve(string $routeName, array $params, string $locale, ?Market $market): string
    {
        if ($this->hostDisambiguatesLocale($market)) {
            return route($routeName, $params);
        }

        return route('localized.'.$routeName, ['locale' => $locale, ...$params]);
    }

    public function hostDisambiguatesLocale(?Market $market): bool
    {
        if ($market === null) {
            return false;
        }

        return $market->marketLanguages()->count() <= 1;
    }
}
