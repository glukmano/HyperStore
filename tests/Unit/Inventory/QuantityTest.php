<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use InvalidArgumentException;
use Modules\Inventory\ValueObjects\Quantity;

test('Quantity handles exact scale 4 decimal operations without floating point loss', function (): void {
    $qty = Quantity::fromString('1.2500');
    expect($qty->toString())->toBe('1.2500')
        ->and($qty->toFloat())->toBe(1.25);

    $addition = $qty->add(Quantity::fromString('2.7500'));
    expect($addition->toString())->toBe('4.0000');

    $subtraction = $addition->subtract(Quantity::fromString('0.5000'));
    expect($subtraction->toString())->toBe('3.5000');

    $multiplication = $subtraction->multiply(2);
    expect($multiplication->toString())->toBe('7.0000');
});

test('Quantity comparisons evaluate correctly', function (): void {
    $ten = Quantity::fromInteger(10);
    $five = Quantity::fromString('5.0000');
    $tenPointZero = Quantity::fromString('10.0000');

    expect($ten->isGreaterThan($five))->toBeTrue()
        ->and($five->isLessThan($ten))->toBeTrue()
        ->and($ten->equals($tenPointZero))->toBeTrue()
        ->and($ten->isGreaterThanOrEqual($tenPointZero))->toBeTrue()
        ->and($five->isLessThanOrEqual($ten))->toBeTrue();
});

test('Quantity throws InvalidArgumentException on invalid non-numeric strings', function (): void {
    expect(fn () => Quantity::fromString('abc'))->toThrow(InvalidArgumentException::class);
});
