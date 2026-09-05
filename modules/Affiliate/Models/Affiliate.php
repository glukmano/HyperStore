<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Affiliate\Enums\AffiliateStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $display_name
 * @property AffiliateStatus $status
 * @property string $payout_currency
 * @property CarbonImmutable $applied_at
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $user
 */
class Affiliate extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliates';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'display_name',
        'status',
        'payout_currency',
        'applied_at',
        'approved_at',
        'approved_by_user_id',
    ];

    protected $casts = [
        'status' => AffiliateStatus::class,
        'applied_at' => 'immutable_datetime',
        'approved_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
