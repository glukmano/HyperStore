<?php

declare(strict_types=1);

namespace Modules\B2B\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\B2B\Enums\CompanyInvoiceStatus;
use Modules\Order\Models\Order;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property int $order_id
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable $due_at
 * @property CompanyInvoiceStatus $status
 * @property ?CarbonImmutable $paid_at
 */
class CompanyInvoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'order_id',
        'amount_minor',
        'currency',
        'due_at',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'due_at' => 'immutable_datetime',
            'status' => CompanyInvoiceStatus::class,
            'paid_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
