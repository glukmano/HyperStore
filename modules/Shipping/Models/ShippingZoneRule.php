<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingZoneRule extends Model
{
    public $timestamps = false;

    protected $table = 'shipping_zone_rules';

    protected $fillable = [
        'shipping_zone_id',
        'rule_type',
        'country_code',
        'region_code',
        'postal_code_pattern',
        'postal_code_range_start',
        'postal_code_range_end',
        'is_exclusion',
        'priority',
        'created_at',
    ];

    protected $casts = [
        'is_exclusion' => 'boolean',
        'priority' => 'integer',
        'created_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }
}
