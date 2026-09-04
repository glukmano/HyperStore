<?php

declare(strict_types=1);

namespace Modules\Cms\DTOs;

final readonly class BlockTypeDefinition
{
    /**
     * @param  array<string, mixed>  $configSchema  Laravel validation rules for this block type's config
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $configSchema,
        public string $viewPath,
        public ?string $icon = null,
    ) {}
}
