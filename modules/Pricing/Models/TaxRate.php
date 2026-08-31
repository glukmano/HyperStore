<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRate extends Model
{
    use BelongsToTenant;

    protected $table = 'tax_rates';

    protected $fillable = [
        'tenant_id',
        'tax_class_id',
        'tax_zone_id',
        'name',
        'rate_percentage',
        'is_compound',
        'priority',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'rate_percentage' => 'string',
        'is_compound' => 'boolean',
        'priority' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function taxZone(): BelongsTo
    {
        return $this->belongsTo(TaxZone::class);
    }
}
