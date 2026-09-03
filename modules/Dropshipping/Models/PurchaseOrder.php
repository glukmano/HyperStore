<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Fulfillment\Models\OrderFulfillment;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $supplier_id
 * @property int|null $order_fulfillment_id
 * @property string $po_number
 * @property string $type
 * @property string $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $shipping_minor
 * @property int $total_minor
 * @property Carbon|null $submitted_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $expected_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Supplier $supplier
 * @property-read OrderFulfillment|null $fulfillment
 * @property-read Collection<int, PurchaseOrderLine> $lines
 * @property-read Collection<int, SupplierInvoice> $invoices
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'supplier_id',
        'order_fulfillment_id',
        'po_number',
        'type',
        'status',
        'currency',
        'subtotal_minor',
        'tax_minor',
        'shipping_minor',
        'total_minor',
        'submitted_at',
        'acknowledged_at',
        'expected_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected $casts = [
        'subtotal_minor' => 'integer',
        'tax_minor' => 'integer',
        'shipping_minor' => 'integer',
        'total_minor' => 'integer',
        'submitted_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'expected_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<OrderFulfillment, $this>
     */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<SupplierInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class, 'purchase_order_id');
    }

    public function getTotalCostMinorAttribute(): int
    {
        return $this->total_minor;
    }
}
