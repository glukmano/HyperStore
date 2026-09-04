<?php

declare(strict_types=1);

namespace Modules\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $banner_id
 * @property string $locale
 * @property ?string $headline
 * @property ?string $cta_text
 * @property ?string $link_url
 */
class BannerTranslation extends Model
{
    protected $fillable = ['banner_id', 'locale', 'headline', 'cta_text', 'link_url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['banner_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Banner, $this>
     */
    public function banner(): BelongsTo
    {
        return $this->belongsTo(Banner::class, 'banner_id');
    }
}
