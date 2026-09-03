<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Dropshipping\Models\Supplier;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $return_item_id
 * @property int $supplier_id
 * @property string $supplier_rma_number
 * @property string|null $supplier_tracking_number
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read ReturnItem $returnItem
 * @property-read Supplier $supplier
 */
class SupplierReturnReference extends Model
{
    use BelongsToTenant;

    protected $table = 'supplier_return_references';

    protected $fillable = [
        'tenant_id',
        'return_item_id',
        'supplier_id',
        'supplier_rma_number',
        'supplier_tracking_number',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<ReturnItem, $this>
     */
    public function returnItem(): BelongsTo
    {
        return $this->belongsTo(ReturnItem::class, 'return_item_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
