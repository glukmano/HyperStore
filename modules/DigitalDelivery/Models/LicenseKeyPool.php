<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Product;

/**
 * Owner Delta §14: the plaintext license value is application-level
 * encrypted at rest (Laravel's `encrypted` cast) — never hashed only,
 * since a genuine license key must sometimes be REVEALED to the customer,
 * unlike a password.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $code_encrypted
 * @property string $status
 * @property ?int $assigned_order_item_id
 */
class LicenseKeyPool extends Model
{
    use BelongsToTenant;

    protected $table = 'license_key_pools';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'code_encrypted',
        'status',
        'assigned_order_item_id',
    ];

    protected function casts(): array
    {
        return [
            'code_encrypted' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
