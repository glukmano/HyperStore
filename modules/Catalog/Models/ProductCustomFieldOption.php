<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_custom_field_id
 * @property string $code
 * @property int $sort_order
 */
class ProductCustomFieldOption extends Model
{
    protected $fillable = [
        'product_custom_field_id',
        'code',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_custom_field_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductCustomField, $this>
     */
    public function customField(): BelongsTo
    {
        return $this->belongsTo(ProductCustomField::class, 'product_custom_field_id');
    }

    /**
     * @return HasMany<ProductCustomFieldOptionTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductCustomFieldOptionTranslation::class, 'product_custom_field_option_id');
    }
}
