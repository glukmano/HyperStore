<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use BelongsToTenant;

    protected $table = 'exchange_rates';

    protected $fillable = [
        'tenant_id',
        'base_currency',
        'target_currency',
        'rate',
        'source',
        'is_stale',
        'effective_at',
    ];

    protected $casts = [
        'rate' => 'string',
        'is_stale' => 'boolean',
        'effective_at' => 'datetime',
    ];
}
