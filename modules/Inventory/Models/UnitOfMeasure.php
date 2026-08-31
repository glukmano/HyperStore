<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'scale',
        'status',
    ];

    protected $casts = [
        'scale' => 'integer',
    ];
}
