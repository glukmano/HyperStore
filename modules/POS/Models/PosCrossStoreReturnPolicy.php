<?php

declare(strict_types=1);

namespace Modules\POS\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Owner Delta §8: an explicit, POS-owned policy toggle — NOT a Phase-23
 * feature flag. Defaults to enabled (same-Tenant cross-store returns
 * allowed); when disabled, a return must be processed at the original
 * Store.
 *
 * @property int $id
 * @property int $tenant_id
 * @property bool $cross_store_returns_enabled
 */
class PosCrossStoreReturnPolicy extends Model
{
    use BelongsToTenant;

    protected $table = 'pos_cross_store_return_policies';

    protected $fillable = [
        'tenant_id',
        'cross_store_returns_enabled',
    ];

    protected function casts(): array
    {
        return [
            'cross_store_returns_enabled' => 'boolean',
        ];
    }

    public static function forTenant(int $tenantId): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId],
            ['cross_store_returns_enabled' => true]
        );
    }
}
