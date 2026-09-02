<?php

declare(strict_types=1);

namespace Modules\Order\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\OrderItem;

/**
 * @property OrderItem $resource
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'variant_id' => $item->variant_id,
            'sku' => $item->sku_snapshot,
            'name' => $item->name_snapshot,
            'product_type' => $item->product_type_snapshot,
            'quantity' => $item->quantity,
            'unit_price_minor' => $item->unit_price_minor,
            'subtotal_minor' => $item->subtotal_minor,
            'discount_minor' => $item->discount_minor,
            'tax_minor' => $item->tax_minor,
            'total_minor' => $item->total_minor,
            'tax_class_id' => $item->tax_class_id,
            'tax_rate_percent' => $item->tax_rate_percent,
            'selected_options' => $item->selected_options_snapshot,
            'customization_metadata' => $item->customization_metadata_snapshot,
        ];
    }
}
