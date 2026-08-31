<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use InvalidArgumentException;
use Modules\Pricing\ValueObjects\MoneyValue;

test('MoneyValue handles 2-decimal currencies (USD, EUR, CHF) without float rounding errors', function (): void {
    $usd = MoneyValue::fromMinor(1050, 'USD'); // $10.50
    expect($usd->getMinorAmount())->toBe(1050)
        ->and($usd->getDecimalAmount())->toBe('10.50')
        ->and($usd->getCurrencyCode())->toBe('USD');

    $addition = $usd->add(MoneyValue::fromMinor(450, 'USD')); // + $4.50 = $15.00
    expect($addition->getMinorAmount())->toBe(1500)
        ->and($addition->getDecimalAmount())->toBe('15.00');
});

test('MoneyValue handles 0-decimal currencies like JPY', function (): void {
    $jpy = MoneyValue::fromMinor(1500, 'JPY'); // ¥1500
    expect($jpy->getMinorAmount())->toBe(1500)
        ->and($jpy->getDecimalAmount())->toBe('1500')
        ->and($jpy->getCurrencyCode())->toBe('JPY');
});

test('MoneyValue handles 3-decimal currencies like KWD', function (): void {
    $kwd = MoneyValue::fromMinor(1250, 'KWD'); // 1.250 KWD
    expect($kwd->getMinorAmount())->toBe(1250)
        ->and($kwd->getDecimalAmount())->toBe('1.250')
        ->and($kwd->getCurrencyCode())->toBe('KWD');
});

test('MoneyValue percentage calculation rounds explicitly', function (): void {
    $price = MoneyValue::fromMinor(10000, 'USD'); // $100.00
    $discount = $price->percentage(15); // 15% of $100 = $15.00
    expect($discount->getMinorAmount())->toBe(1500);

    $oddPrice = MoneyValue::fromMinor(999, 'USD'); // $9.99
    $oddDiscount = $oddPrice->percentage(10); // 10% of $9.99 = $0.999 -> rounds to $1.00 (100 cents)
    expect($oddDiscount->getMinorAmount())->toBe(100);
});

test('MoneyValue throws InvalidArgumentException on mismatched currency operations', function (): void {
    $usd = MoneyValue::fromMinor(1000, 'USD');
    $eur = MoneyValue::fromMinor(1000, 'EUR');

    expect(fn () => $usd->add($eur))->toThrow(InvalidArgumentException::class);
});
