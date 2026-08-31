<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingZone extends Model
{
    use BelongsToTenant;

    protected $table = 'shipping_zones';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'priority',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'priority' => 'integer',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(ShippingZoneRule::class, 'shipping_zone_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShippingZoneAssignment::class, 'shipping_zone_id');
    }

    public function methods(): HasMany
    {
        return $this->hasMany(ShippingMethodZone::class, 'shipping_zone_id');
    }
}
