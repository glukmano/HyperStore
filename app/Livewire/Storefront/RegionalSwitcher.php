<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Core\Localization\Services\LocalizedUrlResolver;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Modules\Customers\Services\CustomerProfileService;
use Throwable;

/**
 * Phase-18 Final Completion Delta §1: the one storefront Locale/Market/
 * Currency switcher, built entirely against the already-completed
 * context-resolution system — never a second resolution path. A selector
 * is hidden outright (not merely disabled) whenever there is nothing
 * meaningful to switch between, per the explicit "do not expose
 * meaningless switchers" rule.
 */
class RegionalSwitcher extends Component
{
    public string $locale = '';

    public string $currency = '';

    public string $marketCode = '';

    /**
     * Captured once during mount() — the REAL page route the user is
     * viewing. Re-querying Route::currentRouteName() later (inside an
     * updated*() hook) would instead reflect Livewire's OWN internal
     * update-endpoint route, since that hook runs while handling the
     * AJAX POST to /livewire/update, not the original page request.
     */
    public string $pageRouteName = '';

    /** @var array<string, mixed> */
    public array $pageRouteParams = [];

    /** @var list<string> */
    public array $availableLocales = [];

    /** @var list<string> */
    public array $availableCurrencies = [];

    /** @var list<string> */
    public array $availableMarketCodes = [];

    private ?Market $resolvedMarket = null;

    private ?Store $resolvedStore = null;

    public function mount(): void
    {
        $this->pageRouteName = (string) (Route::currentRouteName() ?? '');
        $params = request()->route()?->parameters() ?? [];
        unset($params['locale']);
        $this->pageRouteParams = $params;

        $contextManager = app(ContextManager::class);

        $this->locale = (string) ($contextManager->getLocale()->getLocale() ?? '');
        $this->currency = (string) ($contextManager->getCurrency()->getCode() ?? '');

        $marketContext = $contextManager->getMarket();
        $this->marketCode = $marketContext->isResolved() ? (string) $marketContext->getCode() : '';
        $this->resolvedMarket = $marketContext->isResolved() ? Market::find($marketContext->getId()) : null;

        $storeContext = $contextManager->getStore();
        $this->resolvedStore = $storeContext->isResolved() ? Store::find($storeContext->getId()) : null;

        if ($this->resolvedMarket !== null) {
            $this->availableLocales = array_values($this->resolvedMarket->marketLanguages()->pluck('locale_code')->all());
            $this->availableCurrencies = array_values($this->resolvedMarket->marketCurrencies()->pluck('currency_code')->all());
        }

        if ($this->resolvedStore !== null) {
            $this->availableMarketCodes = array_values($this->resolvedStore->markets()
                ->wherePivot('is_active', true)
                ->pluck('markets.code')
                ->all());
        }
    }

    /**
     * Livewire calls this automatically when `locale` changes via
     * `wire:model.live` — the single write path for a Locale switch.
     */
    public function updatedLocale(string $value): void
    {
        if (! in_array($value, $this->availableLocales, true)) {
            $this->locale = $this->availableLocales[0] ?? $value;

            return;
        }

        $this->rememberPreference(locale: $value);
        $this->redirectToCanonicalUrl($value);
    }

    public function updatedCurrency(string $value): void
    {
        if (! in_array($value, $this->availableCurrencies, true)) {
            return;
        }

        $this->rememberPreference(currency: $value);
        $this->redirect(request()->fullUrl(), navigate: false);
    }

    public function updatedMarketCode(string $value): void
    {
        if (! in_array($value, $this->availableMarketCodes, true)) {
            return;
        }

        $this->redirect(request()->fullUrlWithQuery(['market' => $value]), navigate: false);
    }

    private function rememberPreference(?string $locale = null, ?string $currency = null): void
    {
        if ($locale !== null) {
            cookie()->queue(cookie('hs_locale', $locale, 60 * 24 * 365));
        }
        if ($currency !== null) {
            cookie()->queue(cookie('hs_currency', $currency, 60 * 24 * 365));
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        try {
            $profile = app(CustomerProfileService::class)->firstOrCreateFor($user);
            $profile->update(array_filter([
                'preferred_locale' => $locale,
                'preferred_currency' => $currency,
            ], static fn ($value) => $value !== null));
        } catch (Throwable) {
            // No resolved Tenant context (should not happen on a real
            // storefront request) — guest cookie above already covers it.
        }
    }

    private function redirectToCanonicalUrl(string $locale): void
    {
        if ($this->pageRouteName === '') {
            $this->redirect('/', navigate: false);

            return;
        }

        $baseName = str_starts_with($this->pageRouteName, 'localized.')
            ? substr($this->pageRouteName, strlen('localized.'))
            : $this->pageRouteName;

        $url = app(LocalizedUrlResolver::class)->resolve($baseName, $this->pageRouteParams, $locale, $this->resolvedMarket);

        $this->redirect($url, navigate: false);
    }

    public function render(): View
    {
        return view('theme::components.regional-switcher');
    }
}
