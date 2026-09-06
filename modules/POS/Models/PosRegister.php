<?php

declare(strict_types=1);

namespace Modules\POS\Models;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\StoreMarket;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventorySource;

/**
 * Owner Delta §1: a Register resolves an EXACT commercial context via an
 * active StoreMarket relation — never an ambiguous free market_id, and
 * never an ambiguous Store-level "default InventorySource" (the Register
 * itself owns inventory_source_id).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $store_market_id
 * @property int $channel_id
 * @property int $inventory_source_id
 * @property string $code
 * @property string $name
 * @property string $status
 */
class PosRegister extends Model
{
    use BelongsToTenant;

    protected $table = 'pos_registers';

    protected $fillable = [
        'tenant_id',
        'store_market_id',
        'channel_id',
        'inventory_source_id',
        'code',
        'name',
        'status',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (PosRegister $register): void {
            if (empty($register->uuid)) {
                $register->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<StoreMarket, $this>
     */
    public function storeMarket(): BelongsTo
    {
        return $this->belongsTo(StoreMarket::class, 'store_market_id');
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /**
     * @return BelongsTo<InventorySource, $this>
     */
    public function inventorySource(): BelongsTo
    {
        return $this->belongsTo(InventorySource::class, 'inventory_source_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
