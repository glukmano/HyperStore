<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $attribute_set_id
 * @property string $name
 * @property int $sort_order
 */
class AttributeGroup extends Model
{
    protected $fillable = [
        'attribute_set_id',
        'name',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attribute_set_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AttributeSet, $this>
     */
    public function attributeSet(): BelongsTo
    {
        return $this->belongsTo(AttributeSet::class, 'attribute_set_id');
    }
}
