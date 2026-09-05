<?php

declare(strict_types=1);

namespace App\Core\Localization\Services;

use App\Core\Localization\ValueObjects\LocaleCode;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Language;
use App\Core\Stores\Models\Store;

/**
 * One authoritative fallback-chain + direction-source lookup (Phase-18
 * §7 / Owner Delta §2): requested locale → Market default → Store's
 * default Market → registered bare-language match → platform default
 * Language → null (absolute bootstrap failure, no Language row exists
 * at all). Every caller — LocaleManager::direction(), LocaleResolver,
 * the storefront switcher — resolves through this one service so
 * "which Locale is actually active" never drifts between callers.
 */
final class LocaleFallbackResolver
{
    public function resolveActiveLocale(string $requested, ?Market $market = null, ?Store $store = null): ?Language
    {
        if (LocaleCode::isValid($requested)) {
            $normalized = LocaleCode::normalize($requested);

            $exact = Language::query()->where('code', $normalized)->where('is_active', true)->first();
            if ($exact !== null) {
                return $exact;
            }

            $subtag = LocaleCode::languageSubtag($normalized);
            if ($subtag !== $normalized) {
                $bare = Language::query()->where('code', $subtag)->where('is_active', true)->first();
                if ($bare !== null) {
                    return $bare;
                }
            }
        }

        if ($market !== null && $market->default_locale_code !== '') {
            $marketDefault = Language::query()->where('code', $market->default_locale_code)->where('is_active', true)->first();
            if ($marketDefault !== null) {
                return $marketDefault;
            }
        }

        if ($store !== null) {
            $storeMarket = $store->defaultMarket();
            if ($storeMarket !== null && $storeMarket->default_locale_code !== '') {
                $storeMarketDefault = Language::query()->where('code', $storeMarket->default_locale_code)->where('is_active', true)->first();
                if ($storeMarketDefault !== null) {
                    return $storeMarketDefault;
                }
            }
        }

        return Language::query()->where('is_default', true)->where('is_active', true)->first();
    }
}
