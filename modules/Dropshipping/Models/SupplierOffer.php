<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_id
 * @property int $supplier_product_variant_id
 * @property int $supplier_location_id
 * @property string $stock_quantity
 * @property bool $is_available
 * @property int|null $location_wholesale_cost_minor
 * @property int $lead_time_days
 * @property Carbon $synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Supplier $supplier
 * @property-read SupplierProductVariant $supplierProductVariant
 * @property-read SupplierLocation $supplierLocation
 */
class SupplierOffer extends Model
{
    protected $table = 'supplier_offers';

    protected $fillable = [
        'supplier_id',
        'supplier_product_variant_id',
        'supplier_location_id',
        'stock_quantity',
        'is_available',
        'location_wholesale_cost_minor',
        'lead_time_days',
        'synced_at',
    ];

    protected $casts = [
        'stock_quantity' => 'decimal:8',
        'location_wholesale_cost_minor' => 'integer',
        'lead_time_days' => 'integer',
        'is_available' => 'boolean',
        'synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->synced_at)) {
                $model->synced_at = now();
            }
        });
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<SupplierProductVariant, $this>
     */
    public function supplierProductVariant(): BelongsTo
    {
        return $this->belongsTo(SupplierProductVariant::class, 'supplier_product_variant_id');
    }

    /**
     * @return BelongsTo<SupplierLocation, $this>
     */
    public function supplierLocation(): BelongsTo
    {
        return $this->belongsTo(SupplierLocation::class, 'supplier_location_id');
    }

    public function getCostMinorAttribute(): int
    {
        return $this->location_wholesale_cost_minor
            ?? $this->supplierProductVariant->canonical_wholesale_cost_minor
            ?? 0;
    }

    public function getCurrencyAttribute(): string
    {
        return $this->supplierProductVariant->currency ?? 'EUR';
    }

    public function getStockOnHandAttribute(): string
    {
        return (string) $this->stock_quantity;
    }
}
