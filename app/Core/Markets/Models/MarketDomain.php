<?php

declare(strict_types=1);

namespace App\Core\Markets\Models;

use App\Core\Routing\HostnameClaimService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A regional storefront hostname resolves a Store+Market pair, never a
 * Market alone (Phase-18 Owner Delta §4) — one Market can be attached to
 * multiple Stores, so a hostname mapped only to market_id would leave the
 * resolver guessing which Store to serve. Referencing store_markets.id
 * directly makes the pair unambiguous by construction.
 *
 * New/custom domains never default to verified (Owner Delta §6) —
 * is_verified is false until an operator explicitly flips it.
 *
 * @property int $id
 * @property int $store_market_id
 * @property string $domain
 * @property bool $is_verified
 * @property bool $canonical
 */
class MarketDomain extends Model
{
    protected $table = 'market_domains';

    protected $fillable = [
        'store_market_id',
        'domain',
        'is_verified',
        'canonical',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'store_market_id' => 'integer',
            'is_verified' => 'boolean',
            'canonical' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            app(HostnameClaimService::class)->claim($model->domain, 'market', (int) $model->store_market_id);
        });

        static::deleted(function (self $model): void {
            app(HostnameClaimService::class)->release($model->domain);
        });
    }

    /**
     * @return BelongsTo<StoreMarket, $this>
     */
    public function storeMarket(): BelongsTo
    {
        return $this->belongsTo(StoreMarket::class, 'store_market_id');
    }
}
