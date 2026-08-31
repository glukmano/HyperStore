<?php

declare(strict_types=1);

namespace Modules\Inventory\ValueObjects;

use InvalidArgumentException;

final class Quantity
{
    public const int SCALE = 4;

    /** @var numeric-string */
    private string $value;

    private function __construct(string $value)
    {
        /** @var numeric-string $val */
        $val = (string) $value;
        /** @var numeric-string $normalized */
        $normalized = (string) bcadd($val, '0', self::SCALE);
        $this->value = $normalized;
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        if (! is_numeric($trimmed)) {
            throw new InvalidArgumentException("Invalid quantity value [{$value}]. Must be a numeric decimal string.");
        }

        return new self($trimmed);
    }

    public static function fromInteger(int $value): self
    {
        return new self((string) $value);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    /**
     * @return numeric-string
     */
    public function toString(): string
    {
        return $this->value;
    }

    public function toFloat(): float
    {
        return (float) $this->value;
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::SCALE) < 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) > 0;
    }

    public function isGreaterThanOrEqual(self $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) >= 0;
    }

    public function isLessThan(self $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) < 0;
    }

    public function isLessThanOrEqual(self $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) <= 0;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) === 0;
    }

    public function add(self $other): self
    {
        /** @var numeric-string $res */
        $res = (string) bcadd($this->value, $other->value, self::SCALE);

        return new self($res);
    }

    public function subtract(self $other): self
    {
        /** @var numeric-string $res */
        $res = (string) bcsub($this->value, $other->value, self::SCALE);

        return new self($res);
    }

    public function multiply(string|int $factor): self
    {
        $factorStr = (string) $factor;
        if (! is_numeric($factorStr)) {
            throw new InvalidArgumentException('Multiplication factor must be numeric.');
        }

        /** @var numeric-string $res */
        $res = (string) bcmul($this->value, $factorStr, self::SCALE);

        return new self($res);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
