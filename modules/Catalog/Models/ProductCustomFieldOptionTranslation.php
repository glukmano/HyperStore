<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_custom_field_option_id
 * @property string $locale
 * @property string $label
 */
class ProductCustomFieldOptionTranslation extends Model
{
    protected $fillable = [
        'product_custom_field_option_id',
        'locale',
        'label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_custom_field_option_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductCustomFieldOption, $this>
     */
    public function customFieldOption(): BelongsTo
    {
        return $this->belongsTo(ProductCustomFieldOption::class, 'product_custom_field_option_id');
    }
}
