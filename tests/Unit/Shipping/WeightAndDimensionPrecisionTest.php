<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use InvalidArgumentException;
use Modules\Shipping\ValueObjects\Dimension;
use Modules\Shipping\ValueObjects\Weight;
use PHPUnit\Framework\TestCase;

class WeightAndDimensionPrecisionTest extends TestCase
{
    public function test_weight_stores_exact_decimal_string_without_float(): void
    {
        $w = Weight::of('1.2345', 'kg');
        $this->assertSame('1.2345', $w->toKg());
        $this->assertSame('1234.5000', $w->toGrams());
    }

    public function test_weight_unit_conversions(): void
    {
        $wGrams = Weight::of('500', 'g');
        $this->assertSame('0.5000', $wGrams->toKg());

        $wLb = Weight::of('1.0000', 'lb');
        $this->assertSame('0.4535', $wLb->toKg());
    }

    public function test_weight_addition_and_comparison(): void
    {
        $w1 = Weight::of('1.5000', 'kg');
        $w2 = Weight::of('2.5000', 'kg');
        $sum = $w1->add($w2);

        $this->assertSame('4.0000', $sum->toKg());
        $this->assertTrue($sum->isGreaterThan($w1));
        $this->assertTrue($w1->isLessThan($w2));
    }

    public function test_weight_rejects_negative_and_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Weight::of('-1.0000', 'kg');
    }

    public function test_dimension_volume_and_units(): void
    {
        $dim = new Dimension('10', '20', '30', 'cm');
        $this->assertSame('10.0000', $dim->getLengthCm());
        $this->assertSame('20.0000', $dim->getWidthCm());
        $this->assertSame('30.0000', $dim->getHeightCm());
        $this->assertSame('6000.0000', $dim->getVolumeCm3());

        $dimInches = new Dimension('1', '2', '3', 'in');
        $this->assertSame('2.5400', $dimInches->getLengthCm());
        $this->assertSame('5.0800', $dimInches->getWidthCm());
        $this->assertSame('7.6200', $dimInches->getHeightCm());
    }
}
