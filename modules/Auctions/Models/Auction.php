<?php

declare(strict_types=1);

namespace Modules\Auctions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Enums\UnpaidWinnerPolicy;
use Modules\Catalog\Models\Product;

/**
 * Owner Delta §5: the current winner is derived SOLELY from
 * `current_bid_id` — `current_price_minor`/`current_bidder_customer_profile_id`
 * are denormalized read-convenience copies of that same Bid, repointed
 * atomically alongside it, never independently authoritative.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $store_id
 * @property int $product_id
 * @property string $currency
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property ?int $reserve_price_minor
 * @property int $starting_price_minor
 * @property int $bid_increment_minor
 * @property AuctionStatus $status
 * @property ?int $current_bid_id
 * @property ?int $current_price_minor
 * @property ?int $current_bidder_customer_profile_id
 * @property int $winner_payment_window_minutes
 * @property UnpaidWinnerPolicy $unpaid_winner_policy
 * @property ?string $inventory_reservation_key
 * @property int $version
 */
class Auction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'product_id',
        'currency',
        'starts_at',
        'ends_at',
        'reserve_price_minor',
        'starting_price_minor',
        'bid_increment_minor',
        'status',
        'current_bid_id',
        'current_price_minor',
        'current_bidder_customer_profile_id',
        'winner_payment_window_minutes',
        'unpaid_winner_policy',
        'inventory_reservation_key',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'reserve_price_minor' => 'integer',
            'starting_price_minor' => 'integer',
            'bid_increment_minor' => 'integer',
            'status' => AuctionStatus::class,
            'current_price_minor' => 'integer',
            'unpaid_winner_policy' => UnpaidWinnerPolicy::class,
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Auction $auction): void {
            $auction->uuid ??= (string) Str::uuid();
        });
    }

    public function reserveMet(): bool
    {
        if ($this->reserve_price_minor === null) {
            return true;
        }

        return $this->current_price_minor !== null && $this->current_price_minor >= $this->reserve_price_minor;
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Bid, $this>
     */
    public function currentBid(): BelongsTo
    {
        return $this->belongsTo(Bid::class, 'current_bid_id');
    }

    /**
     * @return HasMany<Bid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }
}
