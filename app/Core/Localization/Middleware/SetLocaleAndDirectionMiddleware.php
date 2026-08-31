<?php

declare(strict_types=1);

namespace App\Core\Localization\Middleware;

use App\Core\Localization\Contracts\LocaleManagerInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetLocaleAndDirectionMiddleware
 *
 * Reads the locale from: request parameter → Accept-Language header → app default.
 * Sets the locale on LocaleManager which propagates to App::setLocale().
 *
 * Phase 01: Locale source is basic. Store/market-based locale resolution will
 * be added in Phase 02 once store and market contexts are resolved.
 */
class SetLocaleAndDirectionMiddleware
{
    public function __construct(
        private readonly LocaleManagerInterface $localeManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);
        $this->localeManager->setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        // 1. Explicit ?lang= query param (useful for testing / admin overrides)
        if ($request->has('lang')) {
            $lang = (string) $request->query('lang', '');
            if ($this->isSupported($lang)) {
                return $lang;
            }
        }

        // 2. Accept-Language header (first segment only)
        $acceptLang = $request->getPreferredLanguage($this->localeManager->getSupportedLocales());
        if ($acceptLang !== null && $this->isSupported($acceptLang)) {
            return $acceptLang;
        }

        // 3. App default
        return config('app.locale', 'en');
    }

    private function isSupported(string $locale): bool
    {
        return in_array($locale, $this->localeManager->getSupportedLocales(), strict: true);
    }
}
