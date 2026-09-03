<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_invoice_id
 * @property int $purchase_order_id
 * @property int|null $purchase_order_line_id
 * @property string $supplier_sku_snapshot
 * @property string $description
 * @property string $quantity
 * @property int $unit_cost_minor
 * @property int $line_total_minor
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SupplierInvoice $supplierInvoice
 * @property-read PurchaseOrderLine|null $purchaseOrderLine
 */
class SupplierInvoiceLine extends Model
{
    protected $table = 'supplier_invoice_lines';

    protected $fillable = [
        'supplier_invoice_id',
        'purchase_order_id',
        'purchase_order_line_id',
        'supplier_sku_snapshot',
        'description',
        'quantity',
        'unit_cost_minor',
        'line_total_minor',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'unit_cost_minor' => 'integer',
        'line_total_minor' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
