<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $store_id
 * @property int $order_id
 * @property string $rma_number
 * @property int|null $customer_id
 * @property string $overall_status
 * @property string|null $customer_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Store $store
 * @property-read Order $order
 * @property-read User|null $customer
 * @property-read Collection<int, SellerReturn> $sellerReturns
 */
class ReturnRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'return_requests';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'store_id',
        'order_id',
        'rma_number',
        'customer_id',
        'overall_status',
        'customer_note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getStatusAttribute(): string
    {
        return (string) $this->overall_status;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return HasMany<SellerReturn, $this>
     */
    public function sellerReturns(): HasMany
    {
        return $this->hasMany(SellerReturn::class, 'return_request_id');
    }

    /**
     * @return HasManyThrough<ReturnItem, SellerReturn, $this>
     */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(ReturnItem::class, SellerReturn::class, 'return_request_id', 'seller_return_id');
    }
}
