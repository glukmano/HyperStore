<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Models;

use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $platform_saas_plan_id
 * @property string $license_key_hash
 * @property string $status
 * @property ?CarbonImmutable $valid_until
 * @property array<string, mixed> $override_limits
 * @property array<string, mixed> $override_features
 * @property-read Tenant $tenant
 * @property-read PlatformSaasPlan $plan
 */
class TenantLicense extends Model
{
    protected $table = 'tenant_licenses';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'platform_saas_plan_id',
        'license_key_hash',
        'status',
        'valid_until',
        'override_limits',
        'override_features',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'platform_saas_plan_id' => 'integer',
            'valid_until' => 'immutable_datetime',
            'override_limits' => 'array',
            'override_features' => 'array',
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
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->valid_until !== null && $this->valid_until->isPast()) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || ($this->valid_until !== null && $this->valid_until->isPast());
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<PlatformSaasPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformSaasPlan::class, 'platform_saas_plan_id');
    }
}
