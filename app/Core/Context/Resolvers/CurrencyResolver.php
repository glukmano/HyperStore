<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Context\Contracts\CurrencyContextInterface;
use App\Core\Context\Contracts\CurrencyResolverInterface;
use App\Core\Context\Contracts\MarketContextInterface;
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
        $code = null;

        if ($this->request !== null) {
            $queryCurrency = $this->request->query('currency') ?? $this->request->header('X-Currency-Code');
            if (is_string($queryCurrency) && $queryCurrency !== '') {
                $code = strtoupper(trim($queryCurrency));
            }
        }

        if ($code === null && $this->marketContext !== null && $this->marketContext->isResolved()) {
            $market = Market::query()->find($this->marketContext->getId());
            if ($market !== null && $market->default_currency_code !== '') {
                $code = $market->default_currency_code;
            }
        }

        if ($code === null) {
            $defaultCurrency = Currency::query()->where('is_default', true)->first();
            $code = $defaultCurrency !== null ? $defaultCurrency->code : 'USD';
        }

        return CurrencyContext::from($code);
    }
}
