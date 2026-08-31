<?php

declare(strict_types=1);

namespace App\Core\Markets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $market_id
 * @property string $currency_code
 * @property bool $is_default
 */
class MarketCurrency extends Model
{
    protected $table = 'market_currencies';

    protected $fillable = [
        'market_id',
        'currency_code',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'market_id' => 'integer',
            'is_default' => 'boolean',
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
