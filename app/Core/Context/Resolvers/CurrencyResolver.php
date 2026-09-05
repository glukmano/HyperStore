<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Context\Contracts\CurrencyContextInterface;
use App\Core\Context\Contracts\CurrencyResolverInterface;
use App\Core\Context\Contracts\MarketContextInterface;
use App\Core\Context\Contracts\RegionalPreferenceProviderInterface;
use App\Core\Context\DTOs\CurrencyContext;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use Illuminate\Http\Request;

class CurrencyResolver implements CurrencyResolverInterface
{
    public function __construct(
        private readonly ?Request $request = null,
        private readonly ?MarketContextInterface $marketContext = null,
    ) {}

    public function resolve(): CurrencyContextInterface
    {
        $market = null;
        if ($this->marketContext !== null && $this->marketContext->isResolved()) {
            $market = Market::query()->find($this->marketContext->getId());
        }

        $code = $this->resolveClientRequestedCode();

        // Phase-18 Owner Delta §35/§37: a client-supplied currency is only
        // ever honored when it's an active member of the resolved Market's
        // own allowed-currency set — never trusted as-is.
        if ($code !== null && $market !== null && ! $this->isAllowedForMarket($code, $market)) {
            $code = null;
        }

        if ($code === null && $market !== null && $market->default_currency_code !== '') {
            $code = $market->default_currency_code;
        }

        if ($code === null) {
            $defaultCurrency = Currency::query()->where('is_default', true)->first();
            $code = $defaultCurrency !== null ? $defaultCurrency->code : (string) config('platform.default_currency_code', 'USD');
        }

        return CurrencyContext::from($code);
    }

    private function resolveClientRequestedCode(): ?string
    {
        if ($this->request === null) {
            return null;
        }

        $queryCurrency = $this->request->query('currency') ?? $this->request->header('X-Currency-Code');
        if (is_string($queryCurrency) && $queryCurrency !== '') {
            return strtoupper(trim($queryCurrency));
        }

        $cookieCurrency = $this->request->cookie('hs_currency');
        if (is_string($cookieCurrency) && $cookieCurrency !== '') {
            return strtoupper(trim($cookieCurrency));
        }

        $user = $this->request->user();
        if ($user !== null) {
            return app(RegionalPreferenceProviderInterface::class)->getPreferredCurrency((int) $user->id);
        }

        return null;
    }

    private function isAllowedForMarket(string $code, Market $market): bool
    {
        return $market->marketCurrencies()->where('currency_code', $code)->exists();
    }
}
