<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property string $status
 * @property array<string, mixed> $limits
 * @property array<string, mixed> $feature_entitlements
 * @property ?array<string, mixed> $billing_metadata
 */
class PlatformSaasPlan extends Model
{
    protected $table = 'platform_saas_plans';

    protected $fillable = [
        'uuid',
        'code',
        'name',
        'status',
        'limits',
        'feature_entitlements',
        'billing_metadata',
    ];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'feature_entitlements' => 'array',
            'billing_metadata' => 'array',
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
        return $this->status === 'active';
    }

    public function isDeprecated(): bool
    {
        return in_array($this->status, ['deprecated', 'retired'], true);
    }

    /**
     * @return HasMany<TenantLicense, $this>
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(TenantLicense::class, 'platform_saas_plan_id');
    }
}
