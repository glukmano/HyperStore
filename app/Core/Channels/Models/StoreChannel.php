<?php

declare(strict_types=1);

namespace App\Core\Channels\Models;

use App\Core\Stores\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $store_id
 * @property int $channel_id
 * @property bool $is_active
 * @property bool $is_default
 * @property ?array<string, mixed> $settings
 */
class StoreChannel extends Model
{
    protected $table = 'store_channels';

    protected $fillable = [
        'store_id',
        'channel_id',
        'is_active',
        'is_default',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'channel_id' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }
}
