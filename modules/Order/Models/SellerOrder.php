<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Fulfillment\Models\OrderFulfillment;
use Modules\Marketplace\Models\Vendor;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $store_id
 * @property int $order_id
 * @property string $seller_order_number
 * @property string $seller_type
 * @property int|null $vendor_id
 * @property string $commercial_model
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $shipping_original_minor
 * @property int $shipping_discount_minor
 * @property int $shipping_final_minor
 * @property int $total_minor
 * @property int $commission_total_minor
 * @property string $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Store $store
 * @property-read Order $order
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, SellerOrderItem> $items
 * @property-read Collection<int, OrderFulfillment> $fulfillments
 */
class SellerOrder extends Model
{
    use BelongsToTenant;

    protected $table = 'seller_orders';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'order_id',
        'seller_order_number',
        'seller_type',
        'vendor_id',
        'commercial_model',
        'currency',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'shipping_original_minor',
        'shipping_discount_minor',
        'shipping_final_minor',
        'total_minor',
        'commission_total_minor',
        'status',
        'metadata',
    ];

    protected $casts = [
        'subtotal_minor' => 'integer',
        'discount_minor' => 'integer',
        'tax_minor' => 'integer',
        'shipping_original_minor' => 'integer',
        'shipping_discount_minor' => 'integer',
        'shipping_final_minor' => 'integer',
        'total_minor' => 'integer',
        'commission_total_minor' => 'integer',
        'metadata' => 'array',
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
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return HasMany<SellerOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SellerOrderItem::class, 'seller_order_id');
    }

    /**
     * @return HasMany<OrderFulfillment, $this>
     */
    public function fulfillments(): HasMany
    {
        return $this->hasMany(OrderFulfillment::class, 'seller_order_id');
    }
}
