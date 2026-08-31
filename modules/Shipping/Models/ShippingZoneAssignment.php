<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingZoneAssignment extends Model
{
    public $timestamps = false;

    protected $table = 'shipping_zone_assignments';

    protected $fillable = [
        'shipping_zone_id',
        'store_id',
        'market_id',
        'channel_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }
}
