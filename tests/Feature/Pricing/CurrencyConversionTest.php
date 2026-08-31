<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\Models\ExchangeRate;
use Modules\Pricing\ValueObjects\MoneyValue;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'fx-test-tenant'],
        ['name' => 'FX Test Tenant', 'status' => 'active']
    );

    // 1 USD = 0.90 EUR
    ExchangeRate::create([
        'tenant_id' => $this->tenant->id,
        'base_currency' => 'USD',
        'target_currency' => 'EUR',
        'rate' => '0.90000000',
        'source' => 'manual',
    ]);

    // 1 USD = 150 JPY
    ExchangeRate::create([
        'tenant_id' => $this->tenant->id,
        'base_currency' => 'USD',
        'target_currency' => 'JPY',
        'rate' => '150.00000000',
        'source' => 'manual',
    ]);
});

test('CurrencyConversionService converts USD to EUR and JPY accurately', function (): void {
    $converter = app(CurrencyConversionInterface::class);

    // $100.00 USD -> 90.00 EUR (9000 minor)
    $usd100 = MoneyValue::fromMinor(10000, 'USD');
    $eur = $converter->convert($usd100, 'EUR', $this->tenant->id);

    expect($eur->getCurrencyCode())->toBe('EUR')
        ->and($eur->getMinorAmount())->toBe(9000);

    // $100.00 USD -> 15,000 JPY (15000 minor for 0-decimal JPY)
    $jpy = $converter->convert($usd100, 'JPY', $this->tenant->id);

    expect($jpy->getCurrencyCode())->toBe('JPY')
        ->and($jpy->getMinorAmount())->toBe(15000);
});

test('CurrencyConversionService calculates inverse rate when only forward rate is configured', function (): void {
    $converter = app(CurrencyConversionInterface::class);

    // 90.00 EUR -> approx $100.00 USD
    $eur90 = MoneyValue::fromMinor(9000, 'EUR');
    $usd = $converter->convert($eur90, 'USD', $this->tenant->id);

    expect($usd->getCurrencyCode())->toBe('USD')
        ->and($usd->getMinorAmount())->toBe(10000);
});
