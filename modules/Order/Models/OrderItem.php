<?php

declare(strict_types=1);

namespace Modules\Order\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $order_id
 * @property int|null $product_id
 * @property int|null $variant_id
 * @property string $sku_snapshot
 * @property string $name_snapshot
 * @property string $product_type_snapshot
 * @property string $quantity
 * @property int $unit_price_minor
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int|null $tax_class_id
 * @property string|null $tax_rate_percent
 * @property array<string, mixed>|null $selected_options_snapshot
 * @property array<string, mixed>|null $customization_metadata_snapshot
 * @property-read Order $order
 */
class OrderItem extends Model
{
    use BelongsToTenant;

    protected $table = 'order_items';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'product_id',
        'variant_id',
        'sku_snapshot',
        'name_snapshot',
        'product_type_snapshot',
        'quantity',
        'unit_price_minor',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'total_minor',
        'tax_class_id',
        'tax_rate_percent',
        'selected_options_snapshot',
        'customization_metadata_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'order_id' => 'integer',
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'quantity' => 'decimal:8',
            'unit_price_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'tax_class_id' => 'integer',
            'tax_rate_percent' => 'string',
            'selected_options_snapshot' => 'array',
            'customization_metadata_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
