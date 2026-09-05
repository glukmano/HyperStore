<?php

declare(strict_types=1);

namespace Modules\Affiliate\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Order\Models\OrderItem;

/**
 * The immutable, line-level commission snapshot (Owner Delta correction §3).
 * Never recomputed from live Product/Category/Vendor/CommissionRule records —
 * refunds create compensating App\Core\Payables entries derived from these
 * frozen fields, they never rewrite this row.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $affiliate_conversion_id
 * @property int $order_item_id
 * @property string $currency
 * @property int $commissionable_base_minor
 * @property int $commission_rate_bps
 * @property int $commission_fixed_fee_minor
 * @property int $commission_amount_minor
 * @property string|null $commission_rule_ref
 * @property CarbonImmutable $created_at
 * @property-read AffiliateConversion $conversion
 * @property-read OrderItem $orderItem
 */
class AffiliateConversionItem extends Model
{
    use BelongsToTenant;

    const UPDATED_AT = null;

    protected $table = 'affiliate_conversion_items';

    protected $fillable = [
        'tenant_id',
        'affiliate_conversion_id',
        'order_item_id',
        'currency',
        'commissionable_base_minor',
        'commission_rate_bps',
        'commission_fixed_fee_minor',
        'commission_amount_minor',
        'commission_rule_ref',
    ];

    protected $casts = [
        'commissionable_base_minor' => 'integer',
        'commission_rate_bps' => 'integer',
        'commission_fixed_fee_minor' => 'integer',
        'commission_amount_minor' => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<AffiliateConversion, $this>
     */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(AffiliateConversion::class, 'affiliate_conversion_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
