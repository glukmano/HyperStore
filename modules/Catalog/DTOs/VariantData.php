<?php

declare(strict_types=1);

namespace Modules\Catalog\DTOs;

final readonly class VariantData
{
    /**
     * @param  array<int, int>  $options  e.g. [attribute_id => attribute_option_id]
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public int $productId,
        public string $sku,
        public array $options,
        public ?string $barcode = null,
        public string $status = 'active',
        public int $sortOrder = 0,
        public ?array $metadata = null,
    ) {}
}
