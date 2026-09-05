<?php

declare(strict_types=1);

namespace Modules\B2B\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property string $currency
 * @property int $approved_limit_minor
 */
class CompanyCreditAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'currency',
        'approved_limit_minor',
    ];

    protected function casts(): array
    {
        return [
            'approved_limit_minor' => 'integer',
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
     * @return HasMany<CompanyCreditEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CompanyCreditEntry::class);
    }
}
