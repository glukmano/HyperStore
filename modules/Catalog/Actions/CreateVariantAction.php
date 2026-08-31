<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Contracts\VariantCombinatorInterface;
use Modules\Catalog\DTOs\VariantData;
use Modules\Catalog\Events\VariantCreated;
use Modules\Catalog\Models\ProductVariant;
use Modules\Catalog\Models\ProductVariantOption;

class CreateVariantAction
{
    public function __construct(
        private readonly VariantCombinatorInterface $combinator,
    ) {}

    public function execute(VariantData $data): ProductVariant
    {
        return DB::transaction(function () use ($data): ProductVariant {
            $combinationHash = $this->combinator->computeCombinationHash($data->options);

            /** @var ProductVariant $variant */
            $variant = ProductVariant::create([
                'product_id' => $data->productId,
                'sku' => $data->sku,
                'barcode' => $data->barcode,
                'combination_hash' => $combinationHash,
                'status' => $data->status,
                'sort_order' => $data->sortOrder,
                'metadata' => $data->metadata,
            ]);

            foreach ($data->options as $attributeId => $optionId) {
                ProductVariantOption::create([
                    'variant_id' => $variant->id,
                    'attribute_id' => (int) $attributeId,
                    'attribute_option_id' => (int) $optionId,
                ]);
            }

            VariantCreated::dispatch($variant);

            return $variant->load(['options.attribute', 'options.option']);
        });
    }
}
