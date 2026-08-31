<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarrierService extends Model
{
    public $timestamps = false;

    protected $table = 'carrier_services';

    protected $fillable = [
        'carrier_id',
        'code',
        'name',
        'transit_days_min',
        'transit_days_max',
        'markup_amount',
        'markup_percentage',
        'status',
        'created_at',
    ];

    protected $casts = [
        'transit_days_min' => 'integer',
        'transit_days_max' => 'integer',
        'markup_amount' => 'integer',
        'markup_percentage' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class, 'carrier_id');
    }
}
