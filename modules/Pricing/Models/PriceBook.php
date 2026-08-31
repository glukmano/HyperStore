<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceBook extends Model
{
    use BelongsToTenant;

    protected $table = 'price_books';

    protected $fillable = [
        'tenant_id',
        'store_id',
        'market_id',
        'channel_id',
        'customer_group_id',
        'name',
        'code',
        'currency',
        'priority',
        'is_default',
        'status',
        'valid_from',
        'valid_until',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'priority' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
