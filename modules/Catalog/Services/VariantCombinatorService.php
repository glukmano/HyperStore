<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Modules\Catalog\Contracts\VariantCombinatorInterface;

class VariantCombinatorService implements VariantCombinatorInterface
{
    /**
     * @param  array<int, array<int, int>>  $attributeOptionMatrix  e.g. [attr_id => [opt_id_1, opt_id_2]]
     * @return array<int, array<int, int>> List of combinations e.g. [[attr_id_1 => opt_id_1, attr_id_2 => opt_id_2]]
     */
    public function generateCombinations(array $attributeOptionMatrix): array
    {
        if (empty($attributeOptionMatrix)) {
            return [];
        }

        $result = [[]];

        foreach ($attributeOptionMatrix as $attributeId => $optionIds) {
            $append = [];
            foreach ($result as $product) {
                foreach ($optionIds as $optionId) {
                    $item = $product;
                    $item[(int) $attributeId] = (int) $optionId;
                    $append[] = $item;
                }
            }
            $result = $append;
        }

        return $result;
    }

    /**
     * Order-independent SHA-256 hash
     *
     * @param  array<int, int>  $optionSet  e.g. [attr_id => opt_id]
     */
    public function computeCombinationHash(array $optionSet): string
    {
        ksort($optionSet, SORT_NUMERIC);
        $pairs = [];
        foreach ($optionSet as $attrId => $optId) {
            $pairs[] = "{$attrId}:{$optId}";
        }

        return hash('sha256', implode('|', $pairs));
    }
}
