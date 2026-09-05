<?php

declare(strict_types=1);

namespace Modules\Pricing\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase-20: completes the pre-existing but previously-unbacked
 * `price_books.customer_group_id` / `PromotionContext::$customerGroupId`
 * concept (both existed since Phase-04, with no real table behind them).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property bool $is_active
 */
class CustomerGroup extends Model
{
    use BelongsToTenant;

    protected $table = 'customer_groups';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
