<?php

declare(strict_types=1);

namespace App\Core\DeveloperCenter\DTOs;

final readonly class DeveloperDocument
{
    public function __construct(
        public string $section,
        public string $slug,
        public string $title,
        public string $safeHtml,
    ) {}
}
