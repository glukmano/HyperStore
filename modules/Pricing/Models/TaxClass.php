<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxClass extends Model
{
    use BelongsToTenant;

    protected $table = 'tax_classes';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
