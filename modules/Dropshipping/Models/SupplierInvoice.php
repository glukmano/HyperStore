<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $supplier_id
 * @property int $purchase_order_id
 * @property string $invoice_number
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $shipping_minor
 * @property int $total_minor
 * @property string $status
 * @property Carbon $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Supplier $supplier
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Collection<int, SupplierInvoiceLine> $lines
 */
class SupplierInvoice extends Model
{
    use BelongsToTenant;

    protected $table = 'supplier_invoices';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'supplier_id',
        'purchase_order_id',
        'invoice_number',
        'currency',
        'subtotal_minor',
        'tax_minor',
        'shipping_minor',
        'total_minor',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'subtotal_minor' => 'integer',
        'tax_minor' => 'integer',
        'shipping_minor' => 'integer',
        'total_minor' => 'integer',
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'metadata' => 'array',
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
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<SupplierInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'supplier_invoice_id');
    }

    public function getReconciliationStatusAttribute(): string
    {
        return (string) ($this->metadata['reconciliation_status'] ?? $this->status);
    }
}
