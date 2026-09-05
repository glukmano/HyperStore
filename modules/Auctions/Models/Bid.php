<?php

declare(strict_types=1);

namespace Modules\Auctions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Auctions\Exceptions\AuctionException;
use Modules\Customers\Models\CustomerProfile;

/**
 * Owner Delta §5: genuinely append-only — no `status` column, never
 * updated or deleted after insert. The winner is derived solely from
 * `Auction.current_bid_id`, never from mutating this row.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $auction_id
 * @property int $customer_profile_id
 * @property int $amount_minor
 * @property CarbonImmutable $placed_at
 */
class Bid extends Model
{
    use BelongsToTenant;

    /**
     * `placed_at` IS the row's authoritative timestamp — there is no
     * separate created_at/updated_at column at all.
     */
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'auction_id',
        'customer_profile_id',
        'amount_minor',
        'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'placed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Bid $bid): void {
            $bid->uuid ??= (string) Str::uuid();
        });

        static::updating(function (): void {
            throw new AuctionException('Bid rows are immutable and may never be updated.');
        });

        static::deleting(function (): void {
            throw new AuctionException('Bid rows are immutable and may never be deleted.');
        });
    }

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function bidder(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }
}
