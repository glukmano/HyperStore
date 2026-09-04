<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Per-locale-row translation, matching Modules\Catalog\Models\CategoryTranslation's
 * exact established shape — no title_ar/title_en columns.
 *
 * @property int $id
 * @property int $page_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property ?string $meta_title
 * @property ?string $meta_description
 * @property ?int $og_image_media_id
 */
class PageTranslation extends Model
{
    protected $fillable = ['page_id', 'locale', 'title', 'slug', 'meta_title', 'meta_description', 'og_image_media_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['page_id' => 'integer', 'og_image_media_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function ogImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'og_image_media_id');
    }
}
