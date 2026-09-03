<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Category;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $vendor_id
 * @property int|null $category_id
 * @property int $rate_basis_points
 * @property int $fixed_fee_minor
 * @property string $currency
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor|null $vendor
 * @property-read Category|null $category
 */
class VendorCommissionRule extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_commission_rules';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'category_id',
        'rate_basis_points',
        'fixed_fee_minor',
        'currency',
        'effective_from',
        'effective_to',
        'is_active',
    ];

    protected $casts = [
        'rate_basis_points' => 'integer',
        'fixed_fee_minor' => 'integer',
        'is_active' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
