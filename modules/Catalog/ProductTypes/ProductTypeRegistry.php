<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeInterface;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;

class ProductTypeRegistry implements ProductTypeRegistryInterface
{
    /** @var array<string, ProductTypeInterface> */
    private array $types = [];

    public function register(ProductTypeInterface $productType): void
    {
        $this->types[$productType->getId()] = $productType;
    }

    public function get(string $id): ProductTypeInterface
    {
        return $this->types[$id] ?? new NullProductType($id);
    }

    public function has(string $id): bool
    {
        return isset($this->types[$id]);
    }

    /**
     * @return array<string, ProductTypeInterface>
     */
    public function all(): array
    {
        return $this->types;
    }
}
