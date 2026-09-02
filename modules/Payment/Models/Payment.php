<?php

declare(strict_types=1);

namespace Modules\Payment\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentStatus;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $uuid
 * @property int $order_id
 * @property string $status
 * @property int $amount_minor
 * @property string $currency
 * @property int $authorized_amount_minor
 * @property int $captured_amount_minor
 * @property int $refunded_amount_minor
 * @property Carbon|null $captured_at
 * @property Carbon|null $authorized_at
 * @property Carbon|null $cancelled_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read Collection<int, PaymentTransaction> $transactions
 */
class Payment extends Model
{
    use BelongsToTenant;

    protected $table = 'payments';

    protected $fillable = [
        'tenant_id',
        'uuid',
        'order_id',
        'status',
        'amount_minor',
        'currency',
        'authorized_amount_minor',
        'captured_amount_minor',
        'refunded_amount_minor',
        'captured_at',
        'authorized_at',
        'cancelled_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'order_id' => 'integer',
            'amount_minor' => 'integer',
            'authorized_amount_minor' => 'integer',
            'captured_amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'captured_at' => 'datetime',
            'authorized_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            if (empty($payment->uuid)) {
                $payment->uuid = (string) Str::uuid();
            }
            if (empty($payment->status)) {
                $payment->status = PaymentStatus::PENDING->value;
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

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_id');
    }

    public function requiresReconciliation(): bool
    {
        if ($this->transactions()->where('status', 'unknown')->exists()) {
            return true;
        }

        if ($this->status === PaymentStatus::CAPTURED->value && $this->order->order_status === 'cancelled') {
            return $this->captured_amount_minor > $this->refunded_amount_minor;
        }

        return false;
    }
}
