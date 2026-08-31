<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    use BelongsToTenant;

    protected $table = 'shipping_methods';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'rate_calculator_type',
        'currency',
        'base_amount',
        'handling_fee',
        'min_subtotal',
        'max_subtotal',
        'min_weight',
        'max_weight',
        'priority',
        'status',
        'metadata',
    ];

    protected $casts = [
        'base_amount' => 'integer',
        'handling_fee' => 'integer',
        'min_subtotal' => 'integer',
        'max_subtotal' => 'integer',
        'min_weight' => 'decimal:4',
        'max_weight' => 'decimal:4',
        'priority' => 'integer',
        'metadata' => 'array',
    ];

    public function methodZones(): HasMany
    {
        return $this->hasMany(ShippingMethodZone::class, 'shipping_method_id');
    }

    public function rateRules(): HasMany
    {
        return $this->hasMany(ShippingRateRule::class, 'shipping_method_id')->orderBy('priority', 'desc');
    }
}
