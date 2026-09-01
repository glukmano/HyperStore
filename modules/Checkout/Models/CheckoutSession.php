<?php

declare(strict_types=1);

namespace Modules\Checkout\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $cart_id
 * @property int|null $user_id
 * @property string|null $guest_token_hash
 * @property int $store_id
 * @property int $market_id
 * @property int $channel_id
 * @property string $currency
 * @property string $locale
 * @property string $state
 * @property array<string, mixed>|null $customer_data
 * @property array<string, mixed>|null $shipping_address
 * @property array<string, mixed>|null $billing_address
 * @property array<string, mixed>|null $selected_shipping_quote
 * @property array<string, mixed>|null $pricing_snapshot
 * @property array<string, mixed>|null $tax_snapshot
 * @property array<string, mixed>|null $promotion_snapshot
 * @property list<int>|null $reservation_references
 * @property array<string, mixed>|null $ready_snapshot
 * @property int $evaluated_cart_version
 * @property int $version
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Cart $cart
 * @property-read User|null $user
 * @property-read Store $store
 * @property-read Market $market
 * @property-read Channel $channel
 */
class CheckoutSession extends Model
{
    use BelongsToTenant;

    protected $table = 'checkout_sessions';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'cart_id',
        'user_id',
        'guest_token_hash',
        'store_id',
        'market_id',
        'channel_id',
        'currency',
        'locale',
        'state',
        'customer_data',
        'shipping_address',
        'billing_address',
        'selected_shipping_quote',
        'pricing_snapshot',
        'tax_snapshot',
        'promotion_snapshot',
        'reservation_references',
        'ready_snapshot',
        'evaluated_cart_version',
        'version',
        'expires_at',
    ];

    protected $casts = [
        'customer_data' => 'array',
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'selected_shipping_quote' => 'array',
        'pricing_snapshot' => 'array',
        'tax_snapshot' => 'array',
        'promotion_snapshot' => 'array',
        'reservation_references' => 'array',
        'ready_snapshot' => 'array',
        'evaluated_cart_version' => 'integer',
        'version' => 'integer',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CheckoutSession $session) {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
            if (empty($session->version)) {
                $session->version = 1;
            }
            if (empty($session->expires_at)) {
                $session->expires_at = Carbon::now()->addMinutes(60);
            }
        });
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    public function isTerminal(): bool
    {
        return in_array($this->state, ['ready_for_order', 'expired', 'cancelled', 'failed'], true);
    }

    public function isExpired(): bool
    {
        return $this->state === 'expired' || $this->expires_at->isPast();
    }
}
