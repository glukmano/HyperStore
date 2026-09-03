<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property string $sku_snapshot
 * @property string $name_snapshot
 * @property string $product_type_snapshot
 * @property bool|null $requires_shipping_snapshot
 * @property string $quantity
 * @property int $unit_price_minor
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int|null $tax_class_id
 * @property string|null $tax_rate_percent
 * @property int|null $vendor_id
 * @property string|null $vendor_uuid_snapshot
 * @property string|null $vendor_name_snapshot
 * @property int|null $vendor_listing_id
 * @property string|null $vendor_listing_uuid_snapshot
 * @property int|null $commission_basis_minor
 * @property int|null $commission_rate_bps
 * @property int|null $commission_fixed_fee_minor
 * @property int|null $commission_amount_minor
 * @property string|null $commission_currency
 * @property string|null $commission_rule_ref
 * @property array<string, mixed>|null $selected_options_snapshot
 * @property array<string, mixed>|null $customization_metadata_snapshot
 * @property-read Order $order
 * @property-read SellerOrderItem|null $sellerOrderItem
 * @property-read Collection<int, ReturnItem> $returnItems
 */
class OrderItem extends Model
{
    use BelongsToTenant;

    protected $table = 'order_items';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'order_id',
        'product_id',
        'variant_id',
        'sku_snapshot',
        'name_snapshot',
        'product_type_snapshot',
        'requires_shipping_snapshot',
        'quantity',
        'unit_price_minor',
        'subtotal_minor',
        'line_discount_minor',
        'allocated_cart_discount_minor',
        'discount_minor',
        'taxable_amount_minor',
        'tax_minor',
        'total_minor',
        'tax_class_id',
        'tax_rate_percent',
        'vendor_id',
        'vendor_uuid_snapshot',
        'vendor_name_snapshot',
        'vendor_listing_id',
        'vendor_listing_uuid_snapshot',
        'commission_basis_minor',
        'commission_rate_bps',
        'commission_fixed_fee_minor',
        'commission_amount_minor',
        'commission_currency',
        'commission_rule_ref',
        'selected_options_snapshot',
        'customization_metadata_snapshot',
    ];

    protected $casts = [
        'requires_shipping_snapshot' => 'boolean',
        'unit_price_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'line_discount_minor' => 'integer',
        'allocated_cart_discount_minor' => 'integer',
        'discount_minor' => 'integer',
        'taxable_amount_minor' => 'integer',
        'tax_minor' => 'integer',
        'total_minor' => 'integer',
        'tax_class_id' => 'integer',
        'tax_rate_percent' => 'decimal:4',
        'quantity' => 'decimal:8',
        'commission_basis_minor' => 'integer',
        'commission_rate_bps' => 'integer',
        'commission_fixed_fee_minor' => 'integer',
        'commission_amount_minor' => 'integer',
        'selected_options_snapshot' => 'array',
        'customization_metadata_snapshot' => 'array',
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return HasOne<SellerOrderItem, $this>
     */
    public function sellerOrderItem(): HasOne
    {
        return $this->hasOne(SellerOrderItem::class, 'order_item_id');
    }

    /**
     * @return HasMany<ReturnItem, $this>
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'order_item_id');
    }
}
