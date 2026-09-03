<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierLocation;
use Modules\Fulfillment\Enums\FulfillmentMode;
use Modules\Order\Models\Order;
use Modules\Order\Models\SellerOrder;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $seller_order_id
 * @property int|null $parent_fulfillment_id
 * @property string $fulfillment_number
 * @property string $fulfillment_mode
 * @property int|null $inventory_source_id
 * @property int|null $warehouse_id
 * @property int|null $supplier_id
 * @property int|null $supplier_location_id
 * @property string $status
 * @property array<string, mixed>|null $routing_snapshot
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read SellerOrder $sellerOrder
 * @property-read Order|null $order
 * @property-read OrderFulfillment|null $parent
 * @property-read Collection<int, OrderFulfillment> $children
 * @property-read Collection<int, OrderFulfillmentItem> $items
 * @property-read Collection<int, OrderShipment> $shipments
 * @property-read Supplier|null $supplier
 * @property-read SupplierLocation|null $supplierLocation
 * @property-read PurchaseOrder|null $purchaseOrder
 */
class OrderFulfillment extends Model
{
    use BelongsToTenant;

    protected $table = 'order_fulfillments';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'seller_order_id',
        'parent_fulfillment_id',
        'fulfillment_number',
        'fulfillment_mode',
        'inventory_source_id',
        'warehouse_id',
        'supplier_id',
        'supplier_location_id',
        'status',
        'routing_snapshot',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'routing_snapshot' => 'array',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
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
     * @return BelongsTo<SellerOrder, $this>
     */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    public function getOrderAttribute(): ?Order
    {
        return $this->sellerOrder?->order;
    }

    /**
     * @return BelongsTo<OrderFulfillment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'parent_fulfillment_id');
    }

    /**
     * @return HasMany<OrderFulfillment, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class, 'parent_fulfillment_id');
    }

    /**
     * @return HasMany<OrderFulfillmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderFulfillmentItem::class, 'order_fulfillment_id');
    }

    /**
     * @return HasMany<OrderShipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(OrderShipment::class, 'order_fulfillment_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<SupplierLocation, $this>
     */
    public function supplierLocation(): BelongsTo
    {
        return $this->belongsTo(SupplierLocation::class, 'supplier_location_id');
    }

    /**
     * @return HasOne<PurchaseOrder, $this>
     */
    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class, 'order_fulfillment_id');
    }

    public function getModeAttribute(): string
    {
        return (string) $this->fulfillment_mode;
    }

    public function getTrackingNumberAttribute(): ?string
    {
        return $this->shipments()->first()?->tracking_number;
    }

    public function getCarrierCodeAttribute(): ?string
    {
        return $this->shipments()->first()?->carrier_code;
    }

    public function isHybrid(): bool
    {
        return $this->fulfillment_mode === FulfillmentMode::HYBRID->value;
    }
}
