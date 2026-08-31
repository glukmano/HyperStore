<?php

declare(strict_types=1);

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $attribute_option_id
 * @property string $locale
 * @property string $label
 */
class AttributeOptionTranslation extends Model
{
    protected $fillable = [
        'attribute_option_id',
        'locale',
        'label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attribute_option_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AttributeOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(AttributeOption::class, 'attribute_option_id');
    }
}
