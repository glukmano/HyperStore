<?php

declare(strict_types=1);

namespace App\Core\Stores\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\StoreMarket;
use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $customer_account_scope_override
 * @property ?array<string, mixed> $settings
 */
class Store extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'status',
        'customer_account_scope_override',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'settings' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return HasMany<StoreDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(StoreDomain::class, 'store_id');
    }

    /**
     * @return HasOne<StoreDomain, $this>
     */
    public function primaryDomain(): HasOne
    {
        return $this->hasOne(StoreDomain::class, 'store_id')
            ->where('canonical', true);
    }

    /**
     * @return HasMany<StoreUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(StoreUser::class, 'store_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_users')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    /**
     * @return HasMany<StoreChannel, $this>
     */
    public function storeChannels(): HasMany
    {
        return $this->hasMany(StoreChannel::class, 'store_id');
    }

    /**
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'store_channels')
            ->withPivot('is_active', 'is_default', 'settings')
            ->withTimestamps();
    }

    /**
     * @return HasMany<StoreMarket, $this>
     */
    public function storeMarkets(): HasMany
    {
        return $this->hasMany(StoreMarket::class, 'store_id');
    }

    /**
     * @return BelongsToMany<Market, $this>
     */
    public function markets(): BelongsToMany
    {
        return $this->belongsToMany(Market::class, 'store_markets')
            ->withPivot('is_active', 'is_default')
            ->withTimestamps();
    }

    public function defaultMarket(): ?Market
    {
        return $this->markets()->wherePivot('is_default', true)->first();
    }

    public function defaultChannel(): ?Channel
    {
        return $this->channels()->wherePivot('is_default', true)->first();
    }
}
