<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

interface VariantCombinatorInterface
{
    /**
     * @param  array<int, array<int, int>>  $attributeOptionMatrix  e.g. [attr_id => [opt_id_1, opt_id_2]]
     * @return array<int, array<int, int>> List of combinations e.g. [[attr_id_1 => opt_id_1, attr_id_2 => opt_id_2]]
     */
    public function generateCombinations(array $attributeOptionMatrix): array;

    /**
     * @param  array<int, int>  $optionSet  e.g. [attr_id => opt_id]
     */
    public function computeCombinationHash(array $optionSet): string;
}
