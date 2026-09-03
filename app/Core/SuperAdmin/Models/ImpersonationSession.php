<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Models;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\Vendor;

/**
 * @property int $id
 * @property string $uuid
 * @property int $impersonator_user_id
 * @property int $target_user_id
 * @property ?int $tenant_id
 * @property ?int $store_id
 * @property ?int $vendor_id
 * @property string $status
 * @property string $token_hash
 * @property string $reason
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $terminated_at
 * @property ?string $termination_reason
 * @property-read User $impersonator
 * @property-read User $target
 */
class ImpersonationSession extends Model
{
    protected $table = 'impersonation_sessions';

    protected $fillable = [
        'uuid',
        'impersonator_user_id',
        'target_user_id',
        'tenant_id',
        'store_id',
        'vendor_id',
        'status',
        'token_hash',
        'reason',
        'started_at',
        'expires_at',
        'terminated_at',
        'termination_reason',
    ];

    protected function casts(): array
    {
        return [
            'impersonator_user_id' => 'integer',
            'target_user_id' => 'integer',
            'tenant_id' => 'integer',
            'store_id' => 'integer',
            'vendor_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return in_array($this->status, ['terminated', 'revoked'], true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
