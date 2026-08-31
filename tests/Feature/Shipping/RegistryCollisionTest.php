<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use InvalidArgumentException;
use Modules\Shipping\Calculators\FlatRateCalculator;
use Modules\Shipping\Providers\ManualCarrierProvider;
use Modules\Shipping\Registries\CarrierRegistry;
use Modules\Shipping\Registries\ShippingMethodTypeRegistry;
use Tests\TestCase;

class RegistryCollisionTest extends TestCase
{
    public function test_shipping_method_type_registry_rejects_duplicate_registration_without_override(): void
    {
        $registry = new ShippingMethodTypeRegistry;
        $registry->register('custom_type', FlatRateCalculator::class);

        $this->expectException(InvalidArgumentException::class);
        $registry->register('custom_type', FlatRateCalculator::class, override: false);
    }

    public function test_carrier_registry_rejects_duplicate_registration_without_override(): void
    {
        $registry = new CarrierRegistry;
        $registry->register('custom_carrier', ManualCarrierProvider::class);

        $this->expectException(InvalidArgumentException::class);
        $registry->register('custom_carrier', ManualCarrierProvider::class, override: false);
    }
}
