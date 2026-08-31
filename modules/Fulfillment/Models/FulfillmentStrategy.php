<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class FulfillmentStrategy extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'fulfillment_strategies';

    protected $fillable = [
        'tenant_id',
        'strategy_type',
        'configuration',
        'is_default',
        'created_at',
    ];

    protected $casts = [
        'configuration' => 'array',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
    ];
}
