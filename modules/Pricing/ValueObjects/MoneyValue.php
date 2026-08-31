<?php

declare(strict_types=1);

namespace Modules\Pricing\ValueObjects;

use Brick\Money\Currency;
use Brick\Money\Money;
use InvalidArgumentException;

final class MoneyValue
{
    private Money $money;

    private function __construct(Money $money)
    {
        $this->money = $money;
    }

    public static function fromMinor(int $minorAmount, string $currencyCode): self
    {
        $currency = Currency::of(strtoupper($currencyCode));
        $money = Money::ofMinor($minorAmount, $currency);

        return new self($money);
    }

    public static function fromDecimal(string|int|float $amount, string $currencyCode): self
    {
        $currency = Currency::of(strtoupper($currencyCode));
        $digits = $currency->getDefaultFractionDigits();
        $multiplier = bcpow('10', (string) $digits);
        /** @var numeric-string $amtStr */
        $amtStr = (string) $amount;
        $minor = (int) round((float) bcmul($amtStr, $multiplier, 6));

        return self::fromMinor($minor, $currencyCode);
    }

    public static function zero(string $currencyCode): self
    {
        return self::fromMinor(0, $currencyCode);
    }

    public function getMinorAmount(): int
    {
        return (int) $this->money->getMinorAmount()->toInt();
    }

    public function getCurrencyCode(): string
    {
        return $this->money->getCurrency()->getCurrencyCode();
    }

    public function getDecimalAmount(): string
    {
        return (string) $this->money->getAmount();
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->money->plus($other->money));
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->money->minus($other->money));
    }

    public function multiply(int|string $factor): self
    {
        /** @var numeric-string $fStr */
        $fStr = (string) $factor;
        $minor = (int) round((float) bcmul((string) $this->getMinorAmount(), $fStr, 4));

        return self::fromMinor($minor, $this->getCurrencyCode());
    }

    public function percentage(int|float|string $percentage): self
    {
        /** @var numeric-string $pctStr */
        $pctStr = (string) $percentage;
        $factor = bcdiv($pctStr, '100', 6);
        $discountMinor = (int) round((float) bcmul((string) $this->getMinorAmount(), $factor, 4));

        return self::fromMinor($discountMinor, $this->getCurrencyCode());
    }

    public function isZero(): bool
    {
        return $this->money->isZero();
    }

    public function isPositive(): bool
    {
        return $this->money->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->money->isNegative();
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->money->isGreaterThan($other->money);
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->money->isLessThan($other->money);
    }

    public function format(): string
    {
        $digits = Currency::of($this->getCurrencyCode())->getDefaultFractionDigits();
        if ($digits === 0) {
            return $this->getCurrencyCode().' '.number_format((float) $this->getMinorAmount(), 0);
        }
        $divisor = 10 ** $digits;

        return $this->getCurrencyCode().' '.number_format($this->getMinorAmount() / $divisor, $digits);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->getCurrencyCode() !== $other->getCurrencyCode()) {
            throw new InvalidArgumentException("Currency mismatch: cannot perform operation between [{$this->getCurrencyCode()}] and [{$other->getCurrencyCode()}].");
        }
    }
}
