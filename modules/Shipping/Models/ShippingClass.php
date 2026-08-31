<?php

declare(strict_types=1);

namespace Modules\Shipping\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ShippingClass extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'shipping_classes';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
