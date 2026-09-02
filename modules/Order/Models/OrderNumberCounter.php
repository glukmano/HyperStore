<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $business_date
 * @property int $last_value
 */
class OrderNumberCounter extends Model
{
    use BelongsToTenant;

    protected $table = 'order_number_counters';

    protected $fillable = [
        'tenant_id',
        'business_date',
        'last_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'last_value' => 'integer',
        ];
    }
}
