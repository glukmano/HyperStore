<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_listing_id
 * @property int $store_id
 * @property bool $is_enabled
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read VendorListing $listing
 * @property-read Store $store
 */
class VendorListingStoreAvailability extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_listing_store_availabilities';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_listing_id',
        'store_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
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
     * @return BelongsTo<VendorListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(VendorListing::class, 'vendor_listing_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
