<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $seller_return_id
 * @property int $order_item_id
 * @property string $quantity_requested
 * @property string $quantity_approved
 * @property string $quantity_received
 * @property string|null $condition
 * @property string $restock_action
 * @property string|null $disposition_operation_uuid
 * @property int|null $destination_inventory_source_id
 * @property Carbon|null $disposed_at
 * @property string $action
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read SellerReturn $sellerReturn
 * @property-read OrderItem $orderItem
 * @property-read Collection<int, SupplierReturnReference> $supplierReturnReferences
 */
class ReturnItem extends Model
{
    use BelongsToTenant;

    protected $table = 'return_items';

    protected $fillable = [
        'tenant_id',
        'seller_return_id',
        'order_item_id',
        'quantity_requested',
        'quantity_approved',
        'quantity_received',
        'condition',
        'restock_action',
        'disposition_operation_uuid',
        'destination_inventory_source_id',
        'disposed_at',
        'action',
    ];

    protected $casts = [
        'quantity_requested' => 'decimal:8',
        'quantity_approved' => 'decimal:8',
        'quantity_received' => 'decimal:8',
        'disposed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function getApprovedQuantityAttribute(): string
    {
        return (string) $this->quantity_approved;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<SellerReturn, $this>
     */
    public function sellerReturn(): BelongsTo
    {
        return $this->belongsTo(SellerReturn::class, 'seller_return_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return HasMany<SupplierReturnReference, $this>
     */
    public function supplierReturnReferences(): HasMany
    {
        return $this->hasMany(SupplierReturnReference::class, 'return_item_id');
    }
}
