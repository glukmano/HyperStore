<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Order\Enums\FulfillmentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\PaymentStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property string $order_number
 * @property int $tenant_id
 * @property int $store_id
 * @property int $market_id
 * @property int $channel_id
 * @property int|null $user_id
 * @property string|null $guest_token_hash
 * @property int $checkout_id
 * @property string $currency
 * @property string $locale
 * @property string $order_status
 * @property string $payment_status
 * @property string $fulfillment_status
 * @property int $merchandise_subtotal_minor
 * @property int $discount_total_minor
 * @property int $shipping_total_minor
 * @property int $tax_total_minor
 * @property int $grand_total_minor
 * @property array<string, mixed> $customer_snapshot
 * @property array<string, mixed>|null $shipping_address_snapshot
 * @property array<string, mixed>|null $billing_address_snapshot
 * @property array<string, mixed>|null $pricing_snapshot
 * @property array<string, mixed>|null $tax_snapshot
 * @property array<string, mixed>|null $promotion_snapshot
 * @property array<string, mixed>|null $shipping_snapshot
 * @property array<string, mixed>|null $fulfillment_snapshot
 * @property array<int, array<string, mixed>>|null $reservation_references
 * @property int $version
 * @property Carbon $placed_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Store $store
 * @property-read Market $market
 * @property-read Channel $channel
 * @property-read User|null $user
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusHistory> $statusHistory
 */
class Order extends Model
{
    use BelongsToTenant;

    protected $table = 'orders';

    protected $fillable = [
        'uuid',
        'order_number',
        'tenant_id',
        'store_id',
        'market_id',
        'channel_id',
        'user_id',
        'guest_token_hash',
        'checkout_id',
        'currency',
        'locale',
        'order_status',
        'payment_status',
        'fulfillment_status',
        'merchandise_subtotal_minor',
        'discount_total_minor',
        'shipping_total_minor',
        'tax_total_minor',
        'grand_total_minor',
        'customer_snapshot',
        'shipping_address_snapshot',
        'billing_address_snapshot',
        'pricing_snapshot',
        'tax_snapshot',
        'promotion_snapshot',
        'shipping_snapshot',
        'fulfillment_snapshot',
        'reservation_references',
        'version',
        'placed_at',
        'confirmed_at',
        'completed_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'store_id' => 'integer',
            'market_id' => 'integer',
            'channel_id' => 'integer',
            'user_id' => 'integer',
            'checkout_id' => 'integer',
            'merchandise_subtotal_minor' => 'integer',
            'discount_total_minor' => 'integer',
            'shipping_total_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'grand_total_minor' => 'integer',
            'customer_snapshot' => 'array',
            'shipping_address_snapshot' => 'array',
            'billing_address_snapshot' => 'array',
            'pricing_snapshot' => 'array',
            'tax_snapshot' => 'array',
            'promotion_snapshot' => 'array',
            'shipping_snapshot' => 'array',
            'fulfillment_snapshot' => 'array',
            'reservation_references' => 'array',
            'version' => 'integer',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Order $order) {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
            if (empty($order->version)) {
                $order->version = 1;
            }
            if (empty($order->order_status)) {
                $order->order_status = OrderStatus::PLACED->value;
            }
            if (empty($order->payment_status)) {
                $order->payment_status = PaymentStatus::PENDING->value;
            }
            if (empty($order->fulfillment_status)) {
                $order->fulfillment_status = FulfillmentStatus::UNFULFILLED->value;
            }
        });
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->orderBy('created_at');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Market, $this>
     */
    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, 'market_id');
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function isCancelled(): bool
    {
        return $this->order_status === OrderStatus::CANCELLED->value;
    }

    public function isTerminal(): bool
    {
        return in_array($this->order_status, [
            OrderStatus::COMPLETED->value,
            OrderStatus::CANCELLED->value,
            OrderStatus::FAILED->value,
        ], true);
    }
}
