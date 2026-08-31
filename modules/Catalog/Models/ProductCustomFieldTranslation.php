<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_custom_field_id
 * @property string $locale
 * @property string $label
 * @property ?string $help_text
 * @property ?string $placeholder
 */
class ProductCustomFieldTranslation extends Model
{
    protected $fillable = [
        'product_custom_field_id',
        'locale',
        'label',
        'help_text',
        'placeholder',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_custom_field_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductCustomField, $this>
     */
    public function customField(): BelongsTo
    {
        return $this->belongsTo(ProductCustomField::class, 'product_custom_field_id');
    }
}
