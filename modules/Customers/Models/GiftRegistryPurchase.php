<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;

/**
 * @property int $id
 * @property int $registry_item_id
 * @property int $order_id
 * @property int $order_item_id
 * @property ?int $purchaser_user_id
 * @property int $quantity
 */
class GiftRegistryPurchase extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'registry_item_id',
        'order_id',
        'order_item_id',
        'purchaser_user_id',
        'quantity',
        'purchased_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registry_item_id' => 'integer',
            'order_id' => 'integer',
            'order_item_id' => 'integer',
            'purchaser_user_id' => 'integer',
            'quantity' => 'integer',
            'purchased_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GiftRegistryItem, $this>
     */
    public function registryItem(): BelongsTo
    {
        return $this->belongsTo(GiftRegistryItem::class, 'registry_item_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchaser_user_id');
    }
}
