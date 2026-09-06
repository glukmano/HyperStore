<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_entitlement_id
 * @property string $ip_hash
 * @property ?string $user_agent
 * @property CarbonInterface $accessed_at
 */
class DigitalAccessLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'customer_entitlement_id',
        'ip_hash',
        'user_agent',
        'accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'accessed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CustomerEntitlement, $this>
     */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(CustomerEntitlement::class, 'customer_entitlement_id');
    }
}
