<?php

declare(strict_types=1);

namespace Modules\Payment\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $payment_id
 * @property int|null $payment_operation_key_id
 * @property string $operation_type
 * @property string $status
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $provider_code
 * @property string|null $payment_method_type
 * @property string|null $provider_reference
 * @property string|null $provider_idempotency_key
 * @property string|null $provider_response_code
 * @property string|null $normalized_error_code
 * @property string|null $action_type
 * @property array<string, mixed>|null $action_payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Payment $payment
 * @property-read PaymentOperationKey|null $operationKey
 */
class PaymentTransaction extends Model
{
    use BelongsToTenant;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'tenant_id',
        'uuid',
        'payment_id',
        'payment_operation_key_id',
        'operation_type',
        'status',
        'amount_minor',
        'currency',
        'provider_code',
        'payment_method_type',
        'provider_reference',
        'provider_idempotency_key',
        'provider_response_code',
        'normalized_error_code',
        'action_type',
        'action_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'payment_id' => 'integer',
            'payment_operation_key_id' => 'integer',
            'amount_minor' => 'integer',
            'action_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * @return BelongsTo<PaymentOperationKey, $this>
     */
    public function operationKey(): BelongsTo
    {
        return $this->belongsTo(PaymentOperationKey::class, 'payment_operation_key_id');
    }
}
