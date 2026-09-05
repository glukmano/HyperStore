<?php

declare(strict_types=1);

namespace Modules\Customers\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_profile_id
 * @property string $code
 * @property-read CustomerProfile $customerProfile
 */
class CustomerReferralCode extends Model
{
    use BelongsToTenant;

    protected $table = 'customer_referral_codes';

    protected $fillable = [
        'tenant_id',
        'customer_profile_id',
        'code',
    ];

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }
}
