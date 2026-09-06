<?php

declare(strict_types=1);

namespace Modules\POS\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\Order;

/**
 * Owner Delta §12: presentation only, never economic truth. Line/tax/
 * discount/tender values always come from the immutable Order and tender
 * snapshots — this table freezes only the minimal presentation header
 * (Store display name/address, Register code/name, cashier display name)
 * that could otherwise drift if the live Store/Register/User records are
 * later renamed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $receipt_number
 * @property int $order_id
 * @property int $register_id
 * @property int $register_session_id
 * @property int $cashier_user_id
 * @property array<string, mixed> $presentation_snapshot
 */
class PosReceipt extends Model
{
    use BelongsToTenant;

    protected $table = 'pos_receipts';

    protected $fillable = [
        'tenant_id',
        'receipt_number',
        'order_id',
        'register_id',
        'register_session_id',
        'cashier_user_id',
        'presentation_snapshot',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'presentation_snapshot' => 'array',
            'printed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * @return BelongsTo<PosRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'register_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }
}
