<?php

declare(strict_types=1);

namespace Modules\Catalog\DTOs;

final readonly class StorePublicationData
{
    /**
     * @param  array<string, array<string, ?string>>  $translations  e.g. ['en' => ['slug' => '...', 'name' => '...']]
     * @param  array<int>  $marketIds
     * @param  array<int>  $channelIds
     */
    public function __construct(
        public int $productId,
        public int $storeId,
        public string $status,
        public array $translations,
        public string $visibility = 'visible',
        public bool $isFeatured = false,
        public int $sortOrder = 0,
        public ?string $publishedAt = null,
        public array $marketIds = [],
        public array $channelIds = [],
    ) {}
}
