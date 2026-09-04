<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $blog_post_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property ?string $excerpt
 * @property string $body
 * @property ?string $meta_title
 * @property ?string $meta_description
 */
class BlogPostTranslation extends Model
{
    protected $fillable = ['blog_post_id', 'locale', 'title', 'slug', 'excerpt', 'body', 'meta_title', 'meta_description'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['blog_post_id' => 'integer'];
    }

    /**
     * @return BelongsTo<BlogPost, $this>
     */
    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'blog_post_id');
    }
}
