<?php

declare(strict_types=1);

namespace Modules\Cms\Services;

use App\Core\Support\Contracts\ContentSanitizerInterface;
use App\Models\User;
use Modules\Cms\Models\BlogPost;

final class BlogService
{
    public function __construct(
        private readonly ContentSanitizerInterface $sanitizer,
        private readonly CmsSlugValidator $slugValidator,
    ) {}

    public function create(int $tenantId, ?User $author, ?string $category = null): BlogPost
    {
        return BlogPost::query()->create([
            'tenant_id' => $tenantId,
            'author_user_id' => $author?->id,
            'status' => BlogPost::STATUS_DRAFT,
            'category' => $category,
        ]);
    }

    public function setTranslation(BlogPost $post, string $locale, string $title, string $slug, string $body, ?string $excerpt = null): void
    {
        $this->slugValidator->assertAllowed($slug);

        $post->translations()->updateOrCreate(
            ['locale' => $locale],
            ['title' => $title, 'slug' => $slug, 'body' => $this->sanitizer->sanitizeRichHtml($body), 'excerpt' => $excerpt],
        );
    }

    public function publish(BlogPost $post): BlogPost
    {
        $post->status = BlogPost::STATUS_PUBLISHED;
        $post->published_at ??= now();
        $post->save();

        return $post;
    }
}
