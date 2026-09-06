<?php

declare(strict_types=1);

namespace Modules\POS\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner Delta §10: manual cashier discounts are permission-gated and fully
 * audit-logged — amount/reason/actor are frozen here at apply time (and
 * again onto the historical OrderItem once the Order exists).
 *
 * @property int $id
 * @property int $tenant_id
 * @property ?int $cart_line_id
 * @property ?int $order_item_id
 * @property int $register_session_id
 * @property int $amount_minor
 * @property string $reason
 * @property int $applied_by_user_id
 */
class PosManualDiscountAuditLogEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'pos_manual_discount_audit_log';

    protected $fillable = [
        'tenant_id',
        'cart_line_id',
        'order_item_id',
        'register_session_id',
        'amount_minor',
        'reason',
        'applied_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
