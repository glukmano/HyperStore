<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property int|null $product_limit
 * @property int $staff_limit
 * @property bool $auto_approval
 * @property int $commission_rate_bps
 * @property int $fixed_fee_minor
 * @property string $currency
 * @property bool $can_manage_suppliers
 * @property bool $can_dropship
 * @property bool $has_api_access
 * @property string $ai_tier
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, VendorPlanPrice> $prices
 * @property-read Collection<int, Vendor> $vendors
 */
class VendorPlan extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_plans';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'code',
        'product_limit',
        'staff_limit',
        'auto_approval',
        'commission_rate_bps',
        'fixed_fee_minor',
        'currency',
        'can_manage_suppliers',
        'can_dropship',
        'has_api_access',
        'ai_tier',
        'is_active',
    ];

    protected $casts = [
        'product_limit' => 'integer',
        'staff_limit' => 'integer',
        'auto_approval' => 'boolean',
        'commission_rate_bps' => 'integer',
        'fixed_fee_minor' => 'integer',
        'can_manage_suppliers' => 'boolean',
        'can_dropship' => 'boolean',
        'has_api_access' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return HasMany<VendorPlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(VendorPlanPrice::class, 'vendor_plan_id');
    }

    /**
     * @return HasMany<Vendor, $this>
     */
    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class, 'vendor_plan_id');
    }
}
