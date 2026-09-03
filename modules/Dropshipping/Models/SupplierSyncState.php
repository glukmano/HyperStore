<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int $supplier_id
 * @property string $sync_type
 * @property string $status
 * @property Carbon|null $last_synced_at
 * @property array<string, mixed>|null $cursor_payload
 * @property array<string, mixed>|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Supplier $supplier
 */
class SupplierSyncState extends Model
{
    use BelongsToTenant;

    protected $table = 'supplier_sync_states';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'sync_type',
        'status',
        'last_synced_at',
        'cursor_payload',
        'last_error',
    ];

    protected $casts = [
        'cursor_payload' => 'array',
        'last_error' => 'array',
        'last_synced_at' => 'datetime',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
