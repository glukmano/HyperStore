<?php

declare(strict_types=1);

namespace Modules\Catalog\DTOs;

final readonly class CategoryData
{
    /**
     * @param  array<string, array<string, string>>  $translations  e.g. ['en' => ['name' => '...', 'slug' => '...', 'description' => '...']]
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public int $tenantId,
        public string $code,
        public array $translations,
        public ?int $parentId = null,
        public string $status = 'active',
        public int $sortOrder = 0,
        public ?array $metadata = null,
    ) {}
}
