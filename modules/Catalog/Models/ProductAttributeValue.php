<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_id
 * @property int $attribute_id
 * @property ?string $text_value
 * @property ?int $int_value
 * @property ?float $decimal_value
 * @property ?bool $boolean_value
 * @property ?string $date_value
 * @property ?string $datetime_value
 * @property ?string $file_path
 * @property ?array<string, mixed> $json_value
 */
class ProductAttributeValue extends Model
{
    protected $fillable = [
        'product_id',
        'attribute_id',
        'text_value',
        'int_value',
        'decimal_value',
        'boolean_value',
        'date_value',
        'datetime_value',
        'file_path',
        'json_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'attribute_id' => 'integer',
            'int_value' => 'integer',
            'decimal_value' => 'float',
            'boolean_value' => 'boolean',
            'json_value' => 'array',
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
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }
}
