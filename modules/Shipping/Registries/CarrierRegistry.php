<?php

declare(strict_types=1);

namespace Modules\Shipping\Registries;

use InvalidArgumentException;
use Modules\Shipping\Contracts\CarrierProviderInterface;

class CarrierRegistry
{
    /**
     * @var array<string, class-string<CarrierProviderInterface>>
     */
    private array $providers = [];

    /**
     * @param  class-string<CarrierProviderInterface>  $providerClass
     */
    public function register(string $code, string $providerClass, bool $override = false): void
    {
        if (isset($this->providers[$code]) && ! $override) {
            throw new InvalidArgumentException("Carrier provider [{$code}] is already registered. Explicit override required.");
        }

        $this->providers[$code] = $providerClass;
    }

    public function getProvider(string $code): CarrierProviderInterface
    {
        if (! isset($this->providers[$code])) {
            throw new InvalidArgumentException("Unknown carrier provider [{$code}].");
        }

        $class = $this->providers[$code];

        return app($class);
    }
}
