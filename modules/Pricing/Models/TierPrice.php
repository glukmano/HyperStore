<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Pricing\ValueObjects\MoneyValue;

class TierPrice extends Model
{
    protected $table = 'tier_prices';

    protected $fillable = [
        'price_id',
        'min_quantity',
        'max_quantity',
        'amount_minor',
    ];

    protected $casts = [
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'amount_minor' => 'integer',
    ];

    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    public function getMoney(): MoneyValue
    {
        $curr = ($this->price instanceof Price) ? $this->price->currency : 'USD';

        return MoneyValue::fromMinor($this->amount_minor, $curr);
    }
}
