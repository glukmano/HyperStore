<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use InvalidArgumentException;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'fx-edge-tenant'],
        ['name' => 'FX Edge Tenant', 'status' => 'active']
    );
});

test('MoneyValue exact scaling for KWD (3 decimals) and JPY (0 decimals)', function (): void {
    // KWD has 3 decimal places (1.500 KWD = 1500 minor)
    $kwd = MoneyValue::fromDecimal('1.500', 'KWD');
    expect($kwd->getMinorAmount())->toBe(1500)
        ->and($kwd->getDecimalAmount())->toBe('1.500');

    // JPY has 0 decimal places (2500 JPY = 2500 minor)
    $jpy = MoneyValue::fromDecimal('2500', 'JPY');
    expect($jpy->getMinorAmount())->toBe(2500)
        ->and($jpy->getDecimalAmount())->toBe('2500');
});

test('CurrencyConversionService throws InvalidArgumentException when rate is missing', function (): void {
    $converter = app(CurrencyConversionInterface::class);
    $usd = MoneyValue::fromMinor(1000, 'USD');

    // No conversion rate exists for USD -> BRL
    expect(fn () => $converter->convert($usd, 'BRL', $this->tenant->id))
        ->toThrow(InvalidArgumentException::class, 'Exchange rate not configured between [USD] and [BRL].');
});
