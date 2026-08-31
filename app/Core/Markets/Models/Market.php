<?php

declare(strict_types=1);

namespace App\Core\Markets\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property ?int $tenant_id
 * @property string $name
 * @property string $code
 * @property bool $is_active
 * @property string $default_currency_code
 * @property string $default_locale_code
 * @property string $timezone
 * @property ?array<string, mixed> $settings
 */
class Market extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'is_active',
        'default_currency_code',
        'default_locale_code',
        'timezone',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return HasMany<MarketCountry, $this>
     */
    public function marketCountries(): HasMany
    {
        return $this->hasMany(MarketCountry::class, 'market_id');
    }

    /**
     * @return HasMany<MarketCurrency, $this>
     */
    public function marketCurrencies(): HasMany
    {
        return $this->hasMany(MarketCurrency::class, 'market_id');
    }

    /**
     * @return HasMany<MarketLanguage, $this>
     */
    public function marketLanguages(): HasMany
    {
        return $this->hasMany(MarketLanguage::class, 'market_id');
    }

    /**
     * @return HasMany<StoreMarket, $this>
     */
    public function storeMarkets(): HasMany
    {
        return $this->hasMany(StoreMarket::class, 'market_id');
    }

    /**
     * @return BelongsToMany<Store, $this>
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_markets')
            ->withPivot('is_active', 'is_default')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'market_channels')
            ->withPivot('is_active')
            ->withTimestamps();
    }
}
