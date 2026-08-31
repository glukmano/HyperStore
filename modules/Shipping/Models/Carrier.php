<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrier extends Model
{
    use BelongsToTenant;

    protected $table = 'carriers';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'provider_class',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(CarrierService::class, 'carrier_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(CarrierCredential::class, 'carrier_id');
    }
}
