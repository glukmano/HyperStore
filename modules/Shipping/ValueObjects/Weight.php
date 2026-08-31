<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use InvalidArgumentException;

final readonly class Weight
{
    public const int SCALE = 4;

    public const array VALID_UNITS = ['g', 'kg', 'oz', 'lb'];

    /** @var numeric-string */
    private string $value; // in kg (canonical unit)

    private string $originalUnit;

    /**
     * @param  numeric-string  $valueInKg
     */
    private function __construct(string $valueInKg, string $originalUnit)
    {
        $this->value = $valueInKg;
        $this->originalUnit = $originalUnit;
    }

    public static function of(string|int $amount, string $unit = 'kg'): self
    {
        $cleanUnit = strtolower(trim($unit));
        if (! in_array($cleanUnit, self::VALID_UNITS, true)) {
            throw new InvalidArgumentException("Invalid weight unit [{$unit}]. Valid units: ".implode(', ', self::VALID_UNITS));
        }

        $val = (string) $amount;
        if (! is_numeric($val)) {
            throw new InvalidArgumentException("Weight value must be numeric, [{$val}] given.");
        }

        /** @var numeric-string $val */
        $val = bcadd($val, '0', self::SCALE);
        if (bccomp($val, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException("Weight cannot be negative [{$val}].");
        }

        // Convert to canonical unit (kg)
        /** @var numeric-string $inKg */
        $inKg = match ($cleanUnit) {
            'kg' => $val,
            'g' => bcdiv($val, '1000', self::SCALE),
            'lb' => bcmul($val, '0.45359237', self::SCALE),
            'oz' => bcmul($val, '0.02834952', self::SCALE),
        };

        return new self($inKg, $cleanUnit);
    }

    public static function zero(): self
    {
        return new self('0.0000', 'kg');
    }

    /**
     * @return numeric-string
     */
    public function toKg(): string
    {
        return $this->value;
    }

    /**
     * @return numeric-string
     */
    public function toGrams(): string
    {
        /** @var numeric-string $grams */
        $grams = bcmul($this->value, '1000', self::SCALE);

        return $grams;
    }

    public function add(self $other): self
    {
        /** @var numeric-string $sumKg */
        $sumKg = bcadd($this->value, $other->toKg(), self::SCALE);

        return new self($sumKg, 'kg');
    }

    public function isGreaterThan(self $other): bool
    {
        return bccomp($this->value, $other->toKg(), self::SCALE) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return bccomp($this->value, $other->toKg(), self::SCALE) < 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function getUnit(): string
    {
        return $this->originalUnit;
    }

    /**
     * @return numeric-string
     */
    public function toString(): string
    {
        return $this->value;
    }
}
