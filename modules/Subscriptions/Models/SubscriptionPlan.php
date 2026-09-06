<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Catalog\Models\Product;

/**
 * D.39/D.40: price is NOT stored here — it resolves through the existing
 * PriceResolver/PriceBook exactly like any Product. Only billing cadence
 * metadata lives here.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $product_id
 * @property string $name
 * @property string $billing_interval
 * @property ?int $billing_interval_days
 * @property ?int $trial_days
 * @property ?array<int, int> $dunning_retry_days
 */
class SubscriptionPlan extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'name',
        'billing_interval',
        'billing_interval_days',
        'trial_days',
        'dunning_retry_days',
    ];

    protected function casts(): array
    {
        return [
            'billing_interval_days' => 'integer',
            'trial_days' => 'integer',
            'dunning_retry_days' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SubscriptionPlan $plan): void {
            if (empty($plan->uuid)) {
                $plan->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function nextPeriodEnd(CarbonInterface $from): CarbonImmutable
    {
        $immutableFrom = CarbonImmutable::instance($from);

        return match ($this->billing_interval) {
            'monthly' => $immutableFrom->addMonthNoOverflow(),
            'yearly' => $immutableFrom->addYearNoOverflow(),
            'custom_days' => $immutableFrom->addDays((int) $this->billing_interval_days),
            default => throw new \LogicException("Unknown billing_interval [{$this->billing_interval}]."),
        };
    }
}
