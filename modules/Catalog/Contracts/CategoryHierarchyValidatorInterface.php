<?php

declare(strict_types=1);

namespace Modules\Catalog\Contracts;

use Modules\Catalog\Models\Category;

interface CategoryHierarchyValidatorInterface
{
    public function assertNoCycle(Category $category, ?int $newParentId): void;
}
