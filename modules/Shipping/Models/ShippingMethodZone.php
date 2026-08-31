<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingMethodZone extends Model
{
    public $timestamps = false;

    protected $table = 'shipping_method_zones';

    protected $fillable = [
        'shipping_method_id',
        'shipping_zone_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }
}
