<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use InvalidArgumentException;
use Modules\Catalog\Contracts\CategoryHierarchyValidatorInterface;
use Modules\Catalog\Models\Category;

class CategoryHierarchyService implements CategoryHierarchyValidatorInterface
{
    public function assertNoCycle(Category $category, ?int $newParentId): void
    {
        if ($newParentId === null) {
            return;
        }

        if ($category->id !== 0 && $category->id === $newParentId) {
            throw new InvalidArgumentException('Category cannot be its own parent.');
        }

        $currentId = $newParentId;
        $visited = [$category->id => true];

        while ($currentId !== null) {
            if (isset($visited[$currentId])) {
                throw new InvalidArgumentException('Cyclic relationship detected in category hierarchy.');
            }
            $visited[$currentId] = true;

            /** @var Category|null $parent */
            $parent = Category::query()->find($currentId);
            $currentId = $parent?->parent_id;
        }
    }
}
