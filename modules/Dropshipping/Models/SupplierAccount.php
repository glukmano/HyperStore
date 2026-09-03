<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\Vendor;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $tenant_id
 * @property int $supplier_id
 * @property int|null $vendor_id
 * @property string $account_identifier
 * @property array<string, mixed> $credentials
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Supplier $supplier
 * @property-read Vendor|null $vendor
 */
class SupplierAccount extends Model
{
    use BelongsToTenant;

    protected $table = 'supplier_accounts';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'supplier_id',
        'vendor_id',
        'account_identifier',
        'credentials',
        'status',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
