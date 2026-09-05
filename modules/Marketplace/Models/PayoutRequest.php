<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Payables\Enums\PayoutRequestStatus;
use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property int|null $payout_batch_id
 * @property int $amount_minor
 * @property string $currency
 * @property PayoutRequestStatus $status
 * @property array<string, mixed>|null $destination_details
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 * @property-read PayoutBatch|null $batch
 * @property-read User|null $approver
 * @property-read Collection<int, PayoutRequestAllocation> $allocations
 */
class PayoutRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'payout_requests';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'payout_batch_id',
        'amount_minor',
        'currency',
        'status',
        'destination_details',
        'approved_by_user_id',
        'paid_at',
    ];

    protected $casts = [
        'status' => PayoutRequestStatus::class,
        'amount_minor' => 'integer',
        'destination_details' => 'array',
        'paid_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return BelongsTo<PayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<PayoutRequestAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PayoutRequestAllocation::class, 'payout_request_id');
    }
}
