<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Marketplace\Exceptions\MarketplaceException;
use Modules\Order\Models\OrderItem;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property int|null $order_item_id
 * @property PayableEntryType $entry_type
 * @property string $source_type
 * @property string $source_uuid
 * @property string $currency
 * @property int $amount_minor
 * @property int $commission_amount_minor
 * @property int $net_amount_minor
 * @property PayableAvailabilityStatus $availability_status
 * @property CarbonImmutable|null $available_at
 * @property string|null $held_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 * @property-read OrderItem|null $orderItem
 * @property-read Collection<int, PayoutRequestAllocation> $allocations
 */
class VendorPayableEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_payable_entries';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'order_item_id',
        'entry_type',
        'source_type',
        'source_uuid',
        'currency',
        'amount_minor',
        'commission_amount_minor',
        'net_amount_minor',
        'availability_status',
        'available_at',
        'held_reason',
    ];

    protected $casts = [
        'entry_type' => PayableEntryType::class,
        'availability_status' => PayableAvailabilityStatus::class,
        'amount_minor' => 'integer',
        'commission_amount_minor' => 'integer',
        'net_amount_minor' => 'integer',
        'available_at' => 'immutable_datetime',
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

        static::updating(function (self $model): void {
            // Economic fields are strictly immutable
            $immutableFields = [
                'tenant_id',
                'vendor_id',
                'order_item_id',
                'entry_type',
                'source_type',
                'source_uuid',
                'currency',
                'amount_minor',
                'commission_amount_minor',
                'net_amount_minor',
            ];

            foreach ($immutableFields as $field) {
                if ($model->isDirty($field)) {
                    throw new MarketplaceException("Economic field '{$field}' on VendorPayableEntry is strictly immutable.");
                }
            }
        });

        static::deleting(function (self $model): void {
            throw new MarketplaceException('VendorPayableEntry records are strictly append-only and cannot be deleted.');
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
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return HasMany<PayoutRequestAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PayoutRequestAllocation::class, 'vendor_payable_entry_id');
    }
}
