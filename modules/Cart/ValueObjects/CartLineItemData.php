<?php

declare(strict_types=1);

namespace Modules\Cart\ValueObjects;

final readonly class CartLineItemData
{
    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $customizations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public CartQuantity $quantity,
        public array $options = [],
        public array $customizations = [],
        public array $metadata = []
    ) {}

    public function computeSignature(): string
    {
        $opt = $this->options;
        $cust = $this->customizations;
        ksort($opt);
        ksort($cust);

        $payload = $this->productId.':'.($this->variantId ?? '0').':'.json_encode($opt).':'.json_encode($cust);

        return hash('sha256', $payload);
    }
}
