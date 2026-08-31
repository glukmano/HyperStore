<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Context\Contracts\LocaleContextInterface;
use App\Core\Context\Contracts\LocaleResolverInterface;
use App\Core\Context\Contracts\MarketContextInterface;
use App\Core\Context\DTOs\LocaleContext;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Language;
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

        if ($this->request !== null) {
            $queryLang = $this->request->query('lang');
            if (is_string($queryLang) && $queryLang !== '') {
                $locale = strtolower(trim($queryLang));
            }
        }

        if ($locale === null && $this->marketContext !== null && $this->marketContext->isResolved()) {
            $market = Market::query()->find($this->marketContext->getId());
            if ($market !== null && $market->default_locale_code !== '') {
                $locale = $market->default_locale_code;
            }
        }

        if ($locale === null) {
            $locale = $this->localeManager?->getLocale() ?? config('app.locale', 'en');
        }

        $language = Language::query()->where('code', $locale)->where('is_active', true)->first();
        $direction = $language !== null ? $language->direction : (in_array($locale, ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr');

        return LocaleContext::from($locale, $direction);
    }
}
