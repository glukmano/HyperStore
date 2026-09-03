<?php

declare(strict_types=1);

namespace Modules\Payment\Registries;

use Modules\Payment\Contracts\PaymentGatewayInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\Exceptions\GatewayUnavailableException;

class PaymentGatewayRegistry implements PaymentGatewayRegistryInterface
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $gateways = [];

    private ?string $defaultProvider = null;

    public function register(PaymentGatewayInterface $gateway): void
    {
        $code = $gateway->getProviderCode();
        $this->gateways[$code] = $gateway;

        if ($this->defaultProvider === null) {
            $this->defaultProvider = $code;
        }
    }

    public function get(string $providerCode): PaymentGatewayInterface
    {
        if (! isset($this->gateways[$providerCode])) {
            throw GatewayUnavailableException::forProvider($providerCode);
        }

        return $this->gateways[$providerCode];
    }

    public function has(string $providerCode): bool
    {
        return isset($this->gateways[$providerCode]);
    }

    public function default(): PaymentGatewayInterface
    {
        if ($this->defaultProvider === null || ! isset($this->gateways[$this->defaultProvider])) {
            throw GatewayUnavailableException::forProvider('default');
        }

        return $this->gateways[$this->defaultProvider];
    }

    public function hasDefault(): bool
    {
        return $this->defaultProvider !== null && isset($this->gateways[$this->defaultProvider]);
    }

    public function setDefaultProvider(string $providerCode): void
    {
        if (! isset($this->gateways[$providerCode])) {
            throw GatewayUnavailableException::forProvider($providerCode);
        }

        $this->defaultProvider = $providerCode;
    }
}
