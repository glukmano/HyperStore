<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $order_fulfillment_id
 * @property string $carrier_code
 * @property string $carrier_name
 * @property string $tracking_number
 * @property string|null $tracking_url
 * @property string|null $shipping_label_url
 * @property string $status
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read OrderFulfillment $fulfillment
 */
class OrderShipment extends Model
{
    use BelongsToTenant;

    protected $table = 'order_shipments';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'order_fulfillment_id',
        'carrier_code',
        'carrier_name',
        'tracking_number',
        'tracking_url',
        'shipping_label_url',
        'status',
        'dispatched_at',
        'delivered_at',
    ];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
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

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function getShippedAtAttribute(): ?Carbon
    {
        return $this->dispatched_at;
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }
}
