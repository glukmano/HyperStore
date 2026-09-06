<?php

declare(strict_types=1);

namespace Modules\Payment\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Customers\Models\CustomerProfile;

/**
 * Owner Delta §10: stores only the opaque result of
 * RecurringPaymentGatewayInterface::setupPaymentMethod() — never raw card
 * data.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $customer_profile_id
 * @property string $gateway_provider_code
 * @property string $gateway_reference
 * @property ?string $display_brand
 * @property ?string $display_last4
 * @property bool $is_default
 */
class CustomerPaymentMethod extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'customer_profile_id',
        'gateway_provider_code',
        'gateway_reference',
        'display_brand',
        'display_last4',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CustomerPaymentMethod $method): void {
            if (empty($method->uuid)) {
                $method->uuid = (string) Str::uuid();
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
}
