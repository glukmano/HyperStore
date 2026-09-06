<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D.50/D.58: the authoritative record of what paid for what, snapshotted
 * once at Payment-capture time and never recomputed from mutable state
 * afterward — required for correct, non-blind refund destination
 * allocation.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property string $tender_type
 * @property string $source_reference
 * @property int $amount_minor
 * @property string $currency
 */
class OrderPaymentTenderAllocation extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'tender_type',
        'source_reference',
        'amount_minor',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
