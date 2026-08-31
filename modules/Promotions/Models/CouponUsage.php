<?php

declare(strict_types=1);

namespace Modules\Promotions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponUsage extends Model
{
    use BelongsToTenant;

    protected $table = 'coupon_usages';

    protected $fillable = [
        'tenant_id',
        'coupon_id',
        'customer_id',
        'customer_email',
        'used_at',
        'metadata',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
