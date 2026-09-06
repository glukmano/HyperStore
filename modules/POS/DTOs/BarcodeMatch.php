<?php

declare(strict_types=1);

namespace Modules\POS\DTOs;

final readonly class BarcodeMatch
{
    public function __construct(
        public int $productId,
        public ?int $variantId,
    ) {}
}
