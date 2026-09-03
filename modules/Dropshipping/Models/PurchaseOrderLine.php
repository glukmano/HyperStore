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
use Modules\Order\Models\OrderItem;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_order_id
 * @property int|null $order_item_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $supplier_sku
 * @property string $internal_sku_snapshot
 * @property string $quantity
 * @property int $unit_cost_minor
 * @property int $total_cost_minor
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read OrderItem|null $orderItem
 * @property-read Collection<int, SupplierInvoiceLine> $invoiceLines
 */
class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_order_lines';

    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'order_item_id',
        'product_id',
        'product_variant_id',
        'supplier_sku',
        'internal_sku_snapshot',
        'quantity',
        'unit_cost_minor',
        'total_cost_minor',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'unit_cost_minor' => 'integer',
        'total_cost_minor' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return HasMany<SupplierInvoiceLine, $this>
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'purchase_order_line_id');
    }
}
