<?php

declare(strict_types=1);

namespace App\Core\ReferenceData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property ?string $language_code
 * @property ?string $fallback_locale_code
 * @property string $name
 * @property string $native_name
 * @property string $direction
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
class Language extends Model
{
    protected $fillable = [
        'code',
        'language_code',
        'fallback_locale_code',
        'name',
        'native_name',
        'direction',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }
}
