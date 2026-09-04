<?php

declare(strict_types=1);

namespace Modules\Cms\Contracts;

use Modules\Cms\DTOs\BlockTypeDefinition;

/**
 * The Plugin SDK's 7th registry (ADR-0137), mirroring the exact
 * register()/all()/get() shape of the six existing Phase-16 registries
 * (Navigation, Theme, ProductType, PaymentGateway, Carrier,
 * ShippingMethodType). Rebuilt every request in AppServiceProvider::boot(),
 * preserving the per-request-rebuild disable invariant (ADR-0133).
 */
interface BlockTypeRegistryInterface
{
    public function register(BlockTypeDefinition $definition): void;

    public function has(string $key): bool;

    public function get(string $key): ?BlockTypeDefinition;

    /**
     * @return array<string, BlockTypeDefinition>
     */
    public function all(): array;
}
