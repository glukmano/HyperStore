<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_plan_id
 * @property string $currency
 * @property int $monthly_fee_minor
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read VendorPlan $plan
 */
class VendorPlanPrice extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_plan_prices';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_plan_id',
        'currency',
        'monthly_fee_minor',
    ];

    protected $casts = [
        'monthly_fee_minor' => 'integer',
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
     * @return BelongsTo<VendorPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(VendorPlan::class, 'vendor_plan_id');
    }
}
