<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property string $status_dimension
 * @property string $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string $actor_type
 * @property int|null $actor_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property-read Order $order
 */
class OrderStatusHistory extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'order_status_history';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'status_dimension',
        'from_status',
        'to_status',
        'reason',
        'actor_type',
        'actor_id',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'order_id' => 'integer',
            'actor_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (OrderStatusHistory $history) {
            if (empty($history->created_at)) {
                $history->created_at = Carbon::now();
            }
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
