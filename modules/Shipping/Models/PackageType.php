<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PackageType extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'package_types';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'length_cm',
        'width_cm',
        'height_cm',
        'max_weight_kg',
        'tare_weight_kg',
        'status',
        'created_at',
    ];

    protected $casts = [
        'length_cm' => 'decimal:4',
        'width_cm' => 'decimal:4',
        'height_cm' => 'decimal:4',
        'max_weight_kg' => 'decimal:4',
        'tare_weight_kg' => 'decimal:4',
        'created_at' => 'datetime',
    ];
}
