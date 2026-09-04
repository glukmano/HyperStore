<?php

declare(strict_types=1);

namespace Modules\Seo\DTOs;

final readonly class SeoMetadata
{
    /**
     * @param  array<string, mixed>  $jsonLd
     * @param  array<string, string>  $alternateLocaleUrls  locale => URL
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public string $canonicalUrl,
        public array $jsonLd = [],
        public array $alternateLocaleUrls = [],
        public bool $noindex = false,
    ) {}
}
