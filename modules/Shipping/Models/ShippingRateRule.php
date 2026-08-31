<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRateRule extends Model
{
    public $timestamps = false;

    protected $table = 'shipping_rate_rules';

    protected $fillable = [
        'shipping_method_id',
        'priority',
        'condition_type',
        'conditions_payload',
        'action_type',
        'action_payload',
        'stop_processing',
        'created_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'conditions_payload' => 'array',
        'action_payload' => 'array',
        'stop_processing' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function method(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }
}
