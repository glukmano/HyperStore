<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_id
 * @property string $type
 * @property string $code
 * @property bool $is_required
 * @property ?array<string, mixed> $validation_rules
 * @property int $sort_order
 */
class ProductCustomField extends Model
{
    protected $fillable = [
        'product_id',
        'type',
        'code',
        'is_required',
        'validation_rules',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'is_required' => 'boolean',
            'validation_rules' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return HasMany<ProductCustomFieldTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductCustomFieldTranslation::class, 'product_custom_field_id');
    }

    /**
     * @return HasMany<ProductCustomFieldOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductCustomFieldOption::class, 'product_custom_field_id')->orderBy('sort_order');
    }
}
