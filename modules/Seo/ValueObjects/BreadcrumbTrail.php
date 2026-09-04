<?php

declare(strict_types=1);

namespace Modules\Seo\ValueObjects;

final readonly class BreadcrumbTrail
{
    /**
     * @param  list<array{name: string, url: string}>  $items
     */
    public function __construct(
        public array $items,
    ) {}
}
