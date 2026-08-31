<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

interface ProductTypeRegistryInterface
{
    public function register(ProductTypeInterface $productType): void;

    public function get(string $id): ProductTypeInterface;

    public function has(string $id): bool;

    /**
     * @return array<string, ProductTypeInterface>
     */
    public function all(): array;
}
