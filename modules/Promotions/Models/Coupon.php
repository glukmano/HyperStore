<?php

declare(strict_types=1);

namespace Modules\Promotions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    use BelongsToTenant;

    protected $table = 'coupons';

    protected $fillable = [
        'tenant_id',
        'promotion_id',
        'code',
        'status',
        'valid_from',
        'valid_until',
        'usage_limit',
        'per_customer_limit',
        'times_used',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'usage_limit' => 'integer',
        'per_customer_limit' => 'integer',
        'times_used' => 'integer',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
