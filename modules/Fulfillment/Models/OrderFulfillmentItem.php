<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\OrderItem;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $order_fulfillment_id
 * @property int $order_item_id
 * @property string $quantity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read OrderFulfillment $fulfillment
 * @property-read OrderItem $orderItem
 */
class OrderFulfillmentItem extends Model
{
    use BelongsToTenant;

    protected $table = 'order_fulfillment_items';

    protected $fillable = [
        'tenant_id',
        'order_fulfillment_id',
        'order_item_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
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
     * @return BelongsTo<OrderFulfillment, $this>
     */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
