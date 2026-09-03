<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

interface PaymentGatewayRegistryInterface
{
    public function register(PaymentGatewayInterface $gateway): void;

    public function get(string $providerCode): PaymentGatewayInterface;

    public function has(string $providerCode): bool;

    public function default(): PaymentGatewayInterface;

    public function hasDefault(): bool;

    public function setDefaultProvider(string $providerCode): void;
}
