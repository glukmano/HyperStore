<?php

declare(strict_types=1);

namespace Modules\Fulfillment\DTOs;

final readonly class PackingFailure
{
    public function __construct(
        public string $reason, // oversized_unit, max_weight_exceeded, class_incompatible
        public string $message,
        public ?int $productId = null,
        public ?int $variantId = null
    ) {}
}
