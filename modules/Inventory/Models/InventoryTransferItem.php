<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;

class InventoryTransferItem extends Model
{
    protected $table = 'inventory_transfer_items';

    protected $fillable = [
        'inventory_transfer_id',
        'product_id',
        'product_variant_id',
        'requested_quantity',
        'dispatched_quantity',
        'received_quantity',
    ];

    protected $casts = [
        'requested_quantity' => 'decimal:4',
        'dispatched_quantity' => 'decimal:4',
        'received_quantity' => 'decimal:4',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(InventoryTransfer::class, 'inventory_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
