<?php

declare(strict_types=1);

namespace Modules\Wallet\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Customers\Models\CustomerProfile;
use Modules\Wallet\Enums\StoreValueInstrumentType;

/**
 * Owner Delta §15: instrument_type is set from the base model onward — a
 * Gift Card gets its own account row, never merged into a Customer's
 * Wallet/Store Credit account. customer_profile_id is nullable — a Gift
 * Card may exist and be spent under guest checkout before any Customer has
 * claimed it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property ?int $customer_profile_id
 * @property StoreValueInstrumentType $instrument_type
 * @property string $currency
 * @property string $scope
 * @property string $status
 */
class StoreValueAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_profile_id',
        'instrument_type',
        'currency',
        'scope',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'instrument_type' => StoreValueInstrumentType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StoreValueAccount $account): void {
            if (empty($account->uuid)) {
                $account->uuid = (string) Str::uuid();
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

    /**
     * @return HasMany<StoreValueEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(StoreValueEntry::class);
    }
}
