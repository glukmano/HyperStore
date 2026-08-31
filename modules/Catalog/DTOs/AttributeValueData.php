<?php

declare(strict_types=1);

namespace Modules\Catalog\DTOs;

final readonly class AttributeValueData
{
    /**
     * @param  array<int>|null  $optionIds
     * @param  array<string, mixed>|null  $jsonValue
     */
    public function __construct(
        public int $attributeId,
        public ?string $textValue = null,
        public ?int $intValue = null,
        public ?float $decimalValue = null,
        public ?bool $booleanValue = null,
        public ?string $dateValue = null,
        public ?string $datetimeValue = null,
        public ?string $filePath = null,
        public ?array $optionIds = null,
        public ?array $jsonValue = null,
    ) {}
}
