<?php

declare(strict_types=1);

namespace Modules\Seo\StructuredData;

use InvalidArgumentException;
use Modules\Catalog\Models\Product;
use Modules\Reviews\Contracts\RatingAggregateReaderInterface;
use Modules\Seo\Contracts\StructuredDataBuilderInterface;

final class ProductSchemaBuilder implements StructuredDataBuilderInterface
{
    public function __construct(
        private readonly RatingAggregateReaderInterface $ratingReader,
    ) {}

    public function supports(object $subject): bool
    {
        return $subject instanceof Product;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(object $subject): array
    {
        if (! $subject instanceof Product) {
            throw new InvalidArgumentException(self::class.' only builds schema for '.Product::class.'.');
        }

        $rating = $this->ratingReader->forProduct($subject->id);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $subject->name,
            'sku' => $subject->sku,
        ];

        if ($rating['count'] > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $rating['average'],
                'reviewCount' => $rating['count'],
            ];
        }

        return $schema;
    }
}
