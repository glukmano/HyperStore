<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Context\Contracts\LocaleContextInterface;
use App\Core\Context\Contracts\LocaleResolverInterface;
use App\Core\Context\Contracts\MarketContextInterface;
use App\Core\Context\Contracts\RegionalPreferenceProviderInterface;
use App\Core\Context\DTOs\LocaleContext;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Localization\Services\LocaleFallbackResolver;
use App\Core\Localization\ValueObjects\LocaleCode;
use App\Core\Markets\Models\Market;
use Illuminate\Http\Request;

class LocaleResolver implements LocaleResolverInterface
{
    public function __construct(
        private readonly ?Request $request = null,
        private readonly ?MarketContextInterface $marketContext = null,
        private readonly ?LocaleManagerInterface $localeManager = null,
    ) {}

    public function resolve(): LocaleContextInterface
    {
        $locale = null;

        // Tier 0 — explicit path-prefix locale segment (Owner Delta §7,
        // e.g. /{locale}/product/...), the highest-precedence explicit
        // URL/domain mapping per the detection-precedence order.
        if ($this->request !== null) {
            $routeLocale = $this->request->route('locale');
            if (is_string($routeLocale) && $routeLocale !== '' && LocaleCode::isValid($routeLocale)) {
                $locale = LocaleCode::normalize($routeLocale);
            }
        }

        // Tier 1 — explicit query param (explicit user action this request).
        if ($locale === null && $this->request !== null) {
            $queryLang = $this->request->query('lang');
            if (is_string($queryLang) && $queryLang !== '' && LocaleCode::isValid($queryLang)) {
                $locale = LocaleCode::normalize($queryLang);
            }
        }

        // Tier 2 — guest cookie (an earlier explicit choice this session,
        // §15) then saved authenticated preference (§16/§17). $request->user()
        // resolves via the auth guard directly and does not depend on
        // UserResolver having already run in this pipeline.
        if ($locale === null && $this->request !== null) {
            $cookieLocale = $this->request->cookie('hs_locale');
            if (is_string($cookieLocale) && $cookieLocale !== '' && LocaleCode::isValid($cookieLocale)) {
                $locale = LocaleCode::normalize($cookieLocale);
            }
        }

        if ($locale === null && $this->request !== null) {
            $user = $this->request->user();
            if ($user !== null) {
                $preferred = app(RegionalPreferenceProviderInterface::class)
                    ->getPreferredLocale((int) $user->id);
                if ($preferred !== null) {
                    $locale = $preferred;
                }
            }
        }

        $market = null;
        if ($locale === null && $this->marketContext !== null && $this->marketContext->isResolved()) {
            $market = Market::query()->find($this->marketContext->getId());
            if ($market !== null && $market->default_locale_code !== '') {
                $locale = $market->default_locale_code;
            }
        }

        if ($locale === null) {
            $locale = $this->localeManager?->getLocale() ?? config('app.locale', 'en');
        }

        // Owner Delta §2: direction is never guessed from a hardcoded PHP
        // list — resolve against the registered Locale (with its own
        // fallback chain) and read *that* row's own direction column.
        $language = app(LocaleFallbackResolver::class)->resolveActiveLocale($locale, $market);

        $resolvedLocale = $language !== null ? $language->code : $locale;
        $direction = $language !== null ? $language->direction : 'ltr';

        return LocaleContext::from($resolvedLocale, $direction);
    }
}
