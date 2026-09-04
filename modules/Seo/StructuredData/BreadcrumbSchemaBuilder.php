<?php

declare(strict_types=1);

namespace Modules\Seo\StructuredData;

use InvalidArgumentException;
use Modules\Seo\Contracts\StructuredDataBuilderInterface;
use Modules\Seo\ValueObjects\BreadcrumbTrail;

final class BreadcrumbSchemaBuilder implements StructuredDataBuilderInterface
{
    public function supports(object $subject): bool
    {
        return $subject instanceof BreadcrumbTrail;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(object $subject): array
    {
        if (! $subject instanceof BreadcrumbTrail) {
            throw new InvalidArgumentException(self::class.' only builds schema for '.BreadcrumbTrail::class.'.');
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($subject->items)->values()->map(fn (array $item, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ])->all(),
        ];
    }
}
