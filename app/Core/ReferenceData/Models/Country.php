<?php

declare(strict_types=1);

namespace App\Core\ReferenceData\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $iso2
 * @property string $iso3
 * @property string $name
 * @property string $native_name
 * @property ?string $phone_code
 * @property ?string $default_currency_code
 * @property ?string $default_locale_code
 * @property bool $is_active
 */
class Country extends Model
{
    protected $fillable = [
        'iso2',
        'iso3',
        'name',
        'native_name',
        'phone_code',
        'default_currency_code',
        'default_locale_code',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_code', 'code');
    }

    /**
     * @return BelongsTo<Language, $this>
     */
    public function defaultLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'default_locale_code', 'code');
    }
}
