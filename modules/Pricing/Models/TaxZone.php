<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxZone extends Model
{
    use BelongsToTenant;

    protected $table = 'tax_zones';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'country_code',
        'state_code',
        'postal_code_pattern',
        'priority',
    ];

    protected $casts = [
        'priority' => 'integer',
    ];

    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
