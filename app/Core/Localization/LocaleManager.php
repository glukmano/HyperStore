<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Localization\Enums\Direction;
use App\Core\Localization\Services\LocaleFallbackResolver;
use App\Core\ReferenceData\Models\Language;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;

/**
 * LocaleManager: Manages runtime locale and text direction.
 *
 * Phase-18 Owner Delta §1/§2: supported locales and direction are both
 * DB-driven (the `languages` table), never config('app.supported_locales')
 * or a hardcoded RTL list — those become bootstrap-only seed defaults,
 * consulted solely by ReferenceDataSeeder on a fresh install.
 */
final class LocaleManager implements LocaleManagerInterface
{
    public const string ACTIVE_LOCALES_CACHE_KEY = 'locales:active:v1';

    private string $locale;

    private Direction $direction;

    public function __construct(private readonly Application $app)
    {
        $this->locale = $app->getLocale();
        $this->direction = $this->resolveDirection($this->locale);
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        $this->direction = $this->resolveDirection($locale);
        $this->app->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    public function isRtl(): bool
    {
        return $this->direction === Direction::RTL;
    }

    /**
     * @return array<int, string>
     */
    public function getSupportedLocales(): array
    {
        /** @var array<int, string> $codes */
        $codes = Cache::remember(self::ACTIVE_LOCALES_CACHE_KEY, 3600, function (): array {
            return Language::query()->where('is_active', true)->orderBy('sort_order')->pluck('code')->all();
        });

        return $codes !== [] ? $codes : (array) config('app.supported_locales', ['en']);
    }

    /**
     * Owner Delta §2: requested locale → validate/normalize → resolve an
     * active registered Locale → fallback chain if unsupported → that
     * registered Locale's own `direction` column. Only when literally no
     * Language row exists at all (absolute bootstrap failure, e.g. the
     * very first request before ReferenceDataSeeder has ever run) does
     * this fall back to Direction::LTR.
     */
    private function resolveDirection(string $locale): Direction
    {
        $language = app(LocaleFallbackResolver::class)->resolveActiveLocale($locale);

        return $language !== null ? Direction::from($language->direction) : Direction::LTR;
    }
}
