<?php

declare(strict_types=1);

namespace App\Core\Channels\Models;

use App\Core\Stores\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $type
 * @property string $name
 * @property string $handle
 * @property bool $is_active
 * @property ?array<string, mixed> $settings
 */
class Channel extends Model
{
    protected $fillable = [
        'type',
        'name',
        'handle',
        'is_active',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<StoreChannel, $this>
     */
    public function storeChannels(): HasMany
    {
        return $this->hasMany(StoreChannel::class, 'channel_id');
    }

    /**
     * @return BelongsToMany<Store, $this>
     */
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_channels')
            ->withPivot('is_active', 'is_default', 'settings')
            ->withTimestamps();
    }
}
