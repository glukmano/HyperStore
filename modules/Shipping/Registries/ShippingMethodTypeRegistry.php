<?php

declare(strict_types=1);

namespace Modules\Shipping\Registries;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Modules\Shipping\Calculators\CarrierCalculatedRateCalculator;
use Modules\Shipping\Calculators\FlatRateCalculator;
use Modules\Shipping\Calculators\FreeShippingCalculator;
use Modules\Shipping\Calculators\LocalDeliveryCalculator;
use Modules\Shipping\Calculators\LocalPickupCalculator;
use Modules\Shipping\Calculators\TableRateCalculator;
use Modules\Shipping\Calculators\WeightRateCalculator;
use Modules\Shipping\Contracts\RateCalculatorInterface;

class ShippingMethodTypeRegistry
{
    /**
     * @var array<string, class-string<RateCalculatorInterface>>
     */
    private array $calculators = [];

    public function __construct(private readonly Container $container)
    {
        $this->register('flat_rate', FlatRateCalculator::class);
        $this->register('free_shipping', FreeShippingCalculator::class);
        $this->register('table_rate', TableRateCalculator::class);
        $this->register('weight_based', WeightRateCalculator::class);
        $this->register('local_pickup', LocalPickupCalculator::class);
        $this->register('local_delivery', LocalDeliveryCalculator::class);
        $this->register('carrier_calculated', CarrierCalculatedRateCalculator::class);
    }

    /**
     * @param  class-string<RateCalculatorInterface>  $calculatorClass
     */
    public function register(string $type, string $calculatorClass): void
    {
        $this->calculators[$type] = $calculatorClass;
    }

    public function getCalculator(string $type): RateCalculatorInterface
    {
        if (! isset($this->calculators[$type])) {
            throw new InvalidArgumentException("Unknown shipping rate calculator type [{$type}].");
        }

        return $this->container->make($this->calculators[$type]);
    }

    public function has(string $type): bool
    {
        return isset($this->calculators[$type]);
    }
}
