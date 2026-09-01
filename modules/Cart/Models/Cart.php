<?php

declare(strict_types=1);

namespace Modules\Cart\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string|null $guest_token_hash
 * @property int $store_id
 * @property int $market_id
 * @property int $channel_id
 * @property string $currency
 * @property string $locale
 * @property string $status
 * @property string|null $coupon_code
 * @property int $version
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, CartLine> $lines
 */
class Cart extends Model
{
    use BelongsToTenant;

    protected $table = 'carts';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'guest_token_hash',
        'store_id',
        'market_id',
        'channel_id',
        'currency',
        'locale',
        'status',
        'coupon_code',
        'version',
        'expires_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'expires_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Cart $cart) {
            if (empty($cart->uuid)) {
                $cart->uuid = (string) Str::uuid();
            }
            if (empty($cart->version)) {
                $cart->version = 1;
            }
            if (empty($cart->expires_at)) {
                $cart->expires_at = $cart->user_id !== null
                    ? Carbon::now()->addDays(30)
                    : Carbon::now()->addDays(7);
            }
        });
    }

    /**
     * @return HasMany<CartLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CartLine::class, 'cart_id');
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

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function incrementVersion(): int
    {
        $this->version++;
        $this->save();

        return $this->version;
    }
}
