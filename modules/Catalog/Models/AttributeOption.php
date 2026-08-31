<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $attribute_id
 * @property string $code
 * @property ?string $color_code
 * @property int $sort_order
 */
class AttributeOption extends Model
{
    protected $fillable = [
        'attribute_id',
        'code',
        'color_code',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attribute_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    /**
     * @return HasMany<AttributeOptionTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(AttributeOptionTranslation::class, 'attribute_option_id');
    }

    public function translation(?string $locale = null): ?AttributeOptionTranslation
    {
        $locale = $locale ?? app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', config('app.fallback_locale', 'en'))
            ?? $this->translations->first();
    }

    public function getLabelAttribute(): string
    {
        $trans = $this->translation();

        return $trans !== null ? $trans->label : (string) $this->code;
    }
}
