<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Affiliate\Enums\AffiliateAttributionStrategy;
use Modules\Affiliate\Enums\AffiliateTargetType;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property AffiliateTargetType $target_type
 * @property int|null $target_id
 * @property AffiliateAttributionStrategy $attribution_strategy
 * @property int $attribution_window_days
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class AffiliateCampaign extends Model
{
    use BelongsToTenant;

    protected $table = 'affiliate_campaigns';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'target_type',
        'target_id',
        'attribution_strategy',
        'attribution_window_days',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'target_type' => AffiliateTargetType::class,
        'attribution_strategy' => AffiliateAttributionStrategy::class,
        'attribution_window_days' => 'integer',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'is_active' => 'boolean',
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

    public function isCurrentlyRunning(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = CarbonImmutable::now();
        if ($this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }
        if ($this->ends_at !== null && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }
}
