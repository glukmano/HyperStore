<?php

declare(strict_types=1);

namespace Modules\B2B\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\B2B\Enums\CompanyStatus;
use Modules\Pricing\Models\CustomerGroup;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property ?string $tax_id
 * @property ?array<string, mixed> $billing_address
 * @property CompanyStatus $status
 * @property ?int $customer_group_id
 * @property ?int $payment_terms_days
 * @property ?string $credit_limit_currency
 * @property ?int $credit_limit_minor
 */
class Company extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'tax_id',
        'billing_address',
        'status',
        'customer_group_id',
        'payment_terms_days',
        'credit_limit_currency',
        'credit_limit_minor',
    ];

    protected function casts(): array
    {
        return [
            'billing_address' => 'array',
            'status' => CompanyStatus::class,
            'credit_limit_minor' => 'integer',
        ];
    }

    /**
     * @return HasMany<CompanyUser, $this>
     */
    public function companyUsers(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }
}
