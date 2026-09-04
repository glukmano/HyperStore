<?php

declare(strict_types=1);

namespace Modules\Seo\StructuredData;

use InvalidArgumentException;
use Modules\Cms\Models\BlogPost;
use Modules\Seo\Contracts\StructuredDataBuilderInterface;

final class ArticleSchemaBuilder implements StructuredDataBuilderInterface
{
    public function supports(object $subject): bool
    {
        return $subject instanceof BlogPost;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(object $subject): array
    {
        if (! $subject instanceof BlogPost) {
            throw new InvalidArgumentException(self::class.' only builds schema for '.BlogPost::class.'.');
        }

        $translation = $subject->translation();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $translation?->title,
            'datePublished' => $subject->published_at?->toIso8601String(),
        ];
    }
}
