<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Customers\Models\CustomerProfile;
use Modules\DigitalDelivery\Enums\EntitlementType;

/**
 * Owner Delta D.36: the CURRENT permission — distinct from OrderItem
 * (the immutable historical purchase record). Subscription renewal
 * UPDATES this row's expires_at; OrderItem/renewal-Order rows accumulate
 * one-per-period, immutably, alongside it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $customer_profile_id
 * @property EntitlementType $entitlement_type
 * @property string $source_type
 * @property string $source_uuid
 * @property CarbonInterface $granted_at
 * @property ?CarbonInterface $revoked_at
 * @property ?CarbonInterface $expires_at
 * @property ?int $max_uses
 * @property int $used_count
 */
class CustomerEntitlement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_profile_id',
        'entitlement_type',
        'source_type',
        'source_uuid',
        'granted_at',
        'revoked_at',
        'expires_at',
        'max_uses',
        'used_count',
    ];

    protected function casts(): array
    {
        return [
            'entitlement_type' => EntitlementType::class,
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomerEntitlement $entitlement): void {
            if (empty($entitlement->uuid)) {
                $entitlement->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    public function isAccessible(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }
}
