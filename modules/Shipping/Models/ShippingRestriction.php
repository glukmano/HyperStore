<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRestriction extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'shipping_restrictions';

    protected $fillable = [
        'tenant_id',
        'restriction_type',
        'target_type',
        'target_id',
        'shipping_zone_id',
        'shipping_method_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'target_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }
}
