<?php

declare(strict_types=1);

namespace Modules\Promotions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    use BelongsToTenant;

    protected $table = 'promotions';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'priority',
        'is_exclusive',
        'is_stackable',
        'stop_further_rules',
        'status',
        'valid_from',
        'valid_until',
        'usage_limit',
        'per_customer_limit',
        'times_used',
        'metadata',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_exclusive' => 'boolean',
        'is_stackable' => 'boolean',
        'stop_further_rules' => 'boolean',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'usage_limit' => 'integer',
        'per_customer_limit' => 'integer',
        'times_used' => 'integer',
        'metadata' => 'array',
    ];

    public function conditions(): HasMany
    {
        return $this->hasMany(PromotionCondition::class)->orderBy('sort_order');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PromotionAction::class)->orderBy('sort_order');
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}
