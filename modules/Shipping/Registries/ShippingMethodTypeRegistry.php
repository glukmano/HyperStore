<?php

declare(strict_types=1);

namespace Modules\Shipping\Registries;

use InvalidArgumentException;
use Modules\Shipping\Contracts\RateCalculatorInterface;

class ShippingMethodTypeRegistry
{
    /**
     * @var array<string, class-string<RateCalculatorInterface>>
     */
    private array $calculators = [];

    /**
     * @param  class-string<RateCalculatorInterface>  $calculatorClass
     */
    public function register(string $type, string $calculatorClass, bool $override = false): void
    {
        if (isset($this->calculators[$type]) && ! $override) {
            throw new InvalidArgumentException("Shipping rate calculator type [{$type}] is already registered. Explicit override required.");
        }

        $this->calculators[$type] = $calculatorClass;
    }

    public function has(string $type): bool
    {
        return isset($this->calculators[$type]);
    }

    public function getCalculator(string $type): RateCalculatorInterface
    {
        if (! isset($this->calculators[$type])) {
            throw new InvalidArgumentException("Unknown shipping method rate calculator type [{$type}].");
        }

        $class = $this->calculators[$type];

        return app($class);
    }
}
