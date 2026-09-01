<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class ShippingZoneAssignment extends Model
{
    public static function boot(): void
    {
        parent::boot();

        static::saving(function (ShippingZoneAssignment $item) {
            $zone = $item->zone ?? ($item->shipping_zone_id ? ShippingZone::find($item->shipping_zone_id) : null);
            if ($zone instanceof ShippingZone) {
                if ($item->store_id !== null) {
                    $store = Store::find($item->store_id);
                    if ($store instanceof Store && (int) $store->tenant_id !== (int) $zone->tenant_id) {
                        throw new InvalidArgumentException("Store tenant_id [{$store->tenant_id}] does not match ShippingZone tenant_id [{$zone->tenant_id}].");
                    }
                }
                if ($item->market_id !== null) {
                    $market = Market::find($item->market_id);
                    if ($market instanceof Market && (int) $market->tenant_id !== (int) $zone->tenant_id) {
                        throw new InvalidArgumentException("Market tenant_id [{$market->tenant_id}] does not match ShippingZone tenant_id [{$zone->tenant_id}].");
                    }
                }
                if ($item->channel_id !== null) {
                    $channel = Channel::find($item->channel_id);
                    if (! $channel instanceof Channel || ! $channel->is_active) {
                        throw new InvalidArgumentException("Channel [{$item->channel_id}] is invalid or inactive.");
                    }

                    // Enforce store_channels eligibility
                    if ($item->store_id !== null) {
                        $isEligible = StoreChannel::where('store_id', $item->store_id)
                            ->where('channel_id', $item->channel_id)
                            ->where('is_active', true)
                            ->exists();
                        if (! $isEligible) {
                            throw new InvalidArgumentException("Channel [{$item->channel_id}] is not enabled for Store [{$item->store_id}].");
                        }
                    } else {
                        // Tenant/Market-wide channel assignment: must be enabled for at least one store in tenant
                        $isEligible = StoreChannel::whereIn('store_id', Store::where('tenant_id', $zone->tenant_id)->select('id'))
                            ->where('channel_id', $item->channel_id)
                            ->where('is_active', true)
                            ->exists();
                        if (! $isEligible) {
                            throw new InvalidArgumentException("Channel [{$item->channel_id}] is not enabled for any store in Tenant [{$zone->tenant_id}].");
                        }
                    }
                }
            }
        });
    }

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
