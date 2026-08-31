<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\DTOs\AttributeValueData;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductAttributeOption;
use Modules\Catalog\Models\ProductAttributeValue;

class AssignAttributeValuesAction
{
    /**
     * @param  array<AttributeValueData>  $values
     */
    public function execute(Product $product, array $values): Product
    {
        return DB::transaction(function () use ($product, $values): Product {
            foreach ($values as $item) {
                ProductAttributeValue::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'attribute_id' => $item->attributeId,
                    ],
                    [
                        'text_value' => $item->textValue,
                        'int_value' => $item->intValue,
                        'decimal_value' => $item->decimalValue,
                        'boolean_value' => $item->booleanValue,
                        'date_value' => $item->dateValue,
                        'datetime_value' => $item->datetimeValue,
                        'file_path' => $item->filePath,
                        'json_value' => $item->jsonValue,
                    ]
                );

                if ($item->optionIds !== null) {
                    ProductAttributeOption::where('product_id', $product->id)
                        ->where('attribute_id', $item->attributeId)
                        ->delete();

                    foreach ($item->optionIds as $optionId) {
                        ProductAttributeOption::create([
                            'product_id' => $product->id,
                            'attribute_id' => $item->attributeId,
                            'attribute_option_id' => (int) $optionId,
                        ]);
                    }
                }
            }

            return $product->load(['attributeValues.attribute', 'attributeOptions.option']);
        });
    }
}
