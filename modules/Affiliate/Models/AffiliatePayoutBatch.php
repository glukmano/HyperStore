<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Payables\Enums\PayoutBatchStatus;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property PayoutBatchStatus $status
 * @property string $currency
 * @property int $total_amount_minor
 * @property int $item_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, AffiliatePayoutRequest> $requests
 */
class AffiliatePayoutBatch extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_payout_batches';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'status',
        'currency',
        'total_amount_minor',
        'item_count',
    ];

    protected $casts = [
        'status' => PayoutBatchStatus::class,
        'total_amount_minor' => 'integer',
        'item_count' => 'integer',
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
     * @return HasMany<AffiliatePayoutRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(AffiliatePayoutRequest::class, 'payout_batch_id');
    }
}
