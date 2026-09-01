<?php

declare(strict_types=1);

namespace Modules\Cart\ValueObjects;

use InvalidArgumentException;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\Models\Product;

final readonly class CartQuantity
{
    /** @var numeric-string */
    private string $value;

    /**
     * @param  numeric-string  $value
     */
    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        if (! is_numeric($trimmed) || bccomp($trimmed, '0', 8) <= 0) {
            throw new InvalidArgumentException("Cart quantity must be a positive number, [{$value}] given.");
        }

        /** @var numeric-string $val */
        $val = $trimmed;

        return new self($val);
    }

    public static function fromInt(int $value): self
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("Cart quantity must be greater than zero, [{$value}] given.");
        }

        return new self((string) $value);
    }

    /**
     * Validates quantity against Catalog product type capability / UOM capability.
     */
    public function validateCapability(Product $product, ?ProductTypeRegistryInterface $registry = null): void
    {
        $typeRegistry = $registry ?? app(ProductTypeRegistryInterface::class);

        $allowsFractional = (bool) ($product->metadata['allows_fractional_quantity'] ?? false)
            || (bool) ($product->metadata['allow_fractional'] ?? false);

        if (! $allowsFractional && $typeRegistry->has((string) $product->product_type)) {
            $type = $typeRegistry->get((string) $product->product_type);
            $caps = $type->getCapabilities();
            $allowsFractional = (bool) ($caps['allows_fractional_quantity'] ?? false);
        }

        if (! $allowsFractional) {
            // Must be exact integer
            if (str_contains($this->value, '.')) {
                $decimals = explode('.', $this->value)[1] ?? '';
                if (rtrim($decimals, '0') !== '') {
                    throw new InvalidArgumentException("Product [{$product->id}] requires an integer quantity. Fractional quantity [{$this->value}] is not allowed.");
                }
            }
        }
    }

    public function add(self $other): self
    {
        $sum = bcadd((string) $this->value, (string) $other->value, 8);

        /** @var numeric-string $trimmed */
        $trimmed = rtrim(rtrim($sum, '0'), '.');

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function toInt(): int
    {
        return (int) $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
