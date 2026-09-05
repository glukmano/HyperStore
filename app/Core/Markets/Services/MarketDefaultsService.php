<?php

declare(strict_types=1);

namespace App\Core\Markets\Services;

use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\MarketCurrency;
use App\Core\Markets\Models\MarketLanguage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase-18 Owner Delta §8: Market.default_locale_code/default_currency_code
 * remain the ONE authoritative fields resolvers already read
 * (MarketResolver/LocaleResolver/CurrencyResolver never touch the pivot
 * tables) — market_languages.is_default/market_currencies.is_default are
 * kept transactionally synchronized to them, never an independent second
 * source of truth. A partial UNIQUE index on each pivot backstops this at
 * the DB level against concurrent writes; this service is what keeps the
 * two representations from drifting apart in the first place.
 */
final class MarketDefaultsService
{
    public function setDefaultLocale(Market $market, string $localeCode): void
    {
        DB::transaction(function () use ($market, $localeCode): void {
            $member = MarketLanguage::query()
                ->where('market_id', $market->id)
                ->where('locale_code', $localeCode)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                throw new InvalidArgumentException("Locale [{$localeCode}] is not a member of Market [{$market->code}].");
            }

            MarketLanguage::query()->where('market_id', $market->id)->update(['is_default' => false]);
            $member->update(['is_default' => true]);
            $market->update(['default_locale_code' => $localeCode]);
        });
    }

    public function setDefaultCurrency(Market $market, string $currencyCode): void
    {
        DB::transaction(function () use ($market, $currencyCode): void {
            $member = MarketCurrency::query()
                ->where('market_id', $market->id)
                ->where('currency_code', $currencyCode)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                throw new InvalidArgumentException("Currency [{$currencyCode}] is not a member of Market [{$market->code}].");
            }

            MarketCurrency::query()->where('market_id', $market->id)->update(['is_default' => false]);
            $member->update(['is_default' => true]);
            $market->update(['default_currency_code' => $currencyCode]);
        });
    }

    /**
     * Seeds the initial default-locale/default-currency membership rows for
     * a brand-new Market — without this, a freshly created Market would
     * carry a default_locale_code/default_currency_code that isn't
     * actually a member of its own market_languages/market_currencies yet.
     */
    public function bootstrapDefaults(Market $market): void
    {
        DB::transaction(function () use ($market): void {
            MarketLanguage::query()->firstOrCreate(
                ['market_id' => $market->id, 'locale_code' => $market->default_locale_code],
                ['is_default' => true]
            );

            MarketCurrency::query()->firstOrCreate(
                ['market_id' => $market->id, 'currency_code' => $market->default_currency_code],
                ['is_default' => true]
            );
        });
    }
}
