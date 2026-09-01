<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class ShippingMethodZone extends Model
{
    public static function boot(): void
    {
        parent::boot();

        static::saving(function (ShippingMethodZone $item) {
            $method = $item->method;
            $zone = $item->zone;
            if ($method instanceof ShippingMethod && $zone instanceof ShippingZone) {
                if ((int) $method->tenant_id !== (int) $zone->tenant_id) {
                    throw new InvalidArgumentException("ShippingMethod tenant_id [{$method->tenant_id}] does not match ShippingZone tenant_id [{$zone->tenant_id}].");
                }
            }
        });
    }

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
