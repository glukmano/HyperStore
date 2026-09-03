<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Marketplace\Models\Vendor;
use Modules\Payment\Models\PaymentTransaction;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $return_request_id
 * @property int $seller_order_id
 * @property string $seller_type
 * @property int|null $vendor_id
 * @property string $seller_rma_number
 * @property string $status
 * @property string $refund_eligibility_status
 * @property string|null $refund_operation_uuid
 * @property int|null $payment_refund_transaction_id
 * @property string $refund_status
 * @property Carbon|null $refund_finalized_at
 * @property string $reason_code
 * @property string|null $staff_note
 * @property int $refund_subtotal_minor
 * @property int $refund_discount_reversal_minor
 * @property int $refund_tax_minor
 * @property int $refund_shipping_minor
 * @property int $net_customer_refund_minor
 * @property int $vendor_payable_debit_minor
 * @property int $vendor_commission_reversal_minor
 * @property Carbon|null $approved_at
 * @property Carbon|null $received_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read ReturnRequest $returnRequest
 * @property-read SellerOrder $sellerOrder
 * @property-read Vendor|null $vendor
 * @property-read PaymentTransaction|null $paymentRefundTransaction
 * @property-read Collection<int, ReturnItem> $items
 */
class SellerReturn extends Model
{
    use BelongsToTenant;

    protected $table = 'seller_returns';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'return_request_id',
        'seller_order_id',
        'seller_type',
        'vendor_id',
        'seller_rma_number',
        'status',
        'refund_eligibility_status',
        'refund_operation_uuid',
        'payment_refund_transaction_id',
        'refund_status',
        'refund_finalized_at',
        'reason_code',
        'staff_note',
        'refund_subtotal_minor',
        'refund_discount_reversal_minor',
        'refund_tax_minor',
        'refund_shipping_minor',
        'net_customer_refund_minor',
        'vendor_payable_debit_minor',
        'vendor_commission_reversal_minor',
        'approved_at',
        'received_at',
        'completed_at',
    ];

    protected $casts = [
        'refund_subtotal_minor' => 'integer',
        'refund_discount_reversal_minor' => 'integer',
        'refund_tax_minor' => 'integer',
        'refund_shipping_minor' => 'integer',
        'net_customer_refund_minor' => 'integer',
        'vendor_payable_debit_minor' => 'integer',
        'vendor_commission_reversal_minor' => 'integer',
        'refund_finalized_at' => 'datetime',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
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
     * @return BelongsTo<ReturnRequest, $this>
     */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class, 'return_request_id');
    }

    /**
     * @return BelongsTo<SellerOrder, $this>
     */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function paymentRefundTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_refund_transaction_id');
    }

    /**
     * @return HasMany<ReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'seller_return_id');
    }
}
