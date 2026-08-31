<?php

declare(strict_types=1);

namespace Modules\Catalog\DTOs;

final readonly class ProductData
{
    /**
     * @param  array<string, array<string, ?string>>  $translations  e.g. ['en' => ['name' => '...', 'short_description' => '...', 'description' => '...']]
     * @param  array<int>  $categoryIds
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public int $tenantId,
        public string $productType,
        public string $sku,
        public array $translations,
        public ?int $brandId = null,
        public ?int $attributeSetId = null,
        public ?string $barcode = null,
        public ?string $mpn = null,
        public string $status = 'draft',
        public array $categoryIds = [],
        public ?int $primaryCategoryId = null,
        public ?array $metadata = null,
    ) {}
}
