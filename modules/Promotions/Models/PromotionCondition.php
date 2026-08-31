<?php

declare(strict_types=1);

namespace Modules\Promotions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionCondition extends Model
{
    protected $table = 'promotion_conditions';

    protected $fillable = [
        'promotion_id',
        'condition_type',
        'parameters',
        'sort_order',
    ];

    protected $casts = [
        'parameters' => 'array',
        'sort_order' => 'integer',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
