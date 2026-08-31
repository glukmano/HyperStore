<?php

declare(strict_types=1);

namespace App\Core\Markets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $market_id
 * @property string $country_code
 */
class MarketCountry extends Model
{
    protected $table = 'market_countries';

    protected $fillable = [
        'market_id',
        'country_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'market_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }
}
