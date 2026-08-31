<?php

declare(strict_types=1);

namespace Modules\Catalog\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Contracts\CategoryHierarchyValidatorInterface;
use Modules\Catalog\DTOs\CategoryData;
use Modules\Catalog\Events\CategoryCreated;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\CategoryTranslation;

class CreateCategoryAction
{
    public function __construct(
        private readonly CategoryHierarchyValidatorInterface $hierarchyValidator,
    ) {}

    public function execute(CategoryData $data): Category
    {
        return DB::transaction(function () use ($data): Category {
            $tempCat = new Category(['id' => 0, 'tenant_id' => $data->tenantId]);
            $this->hierarchyValidator->assertNoCycle($tempCat, $data->parentId);

            /** @var Category $category */
            $category = Category::create([
                'tenant_id' => $data->tenantId,
                'parent_id' => $data->parentId,
                'code' => $data->code,
                'status' => $data->status,
                'sort_order' => $data->sortOrder,
                'metadata' => $data->metadata,
            ]);

            foreach ($data->translations as $locale => $trans) {
                CategoryTranslation::create([
                    'category_id' => $category->id,
                    'locale' => $locale,
                    'name' => $trans['name'] ?? $data->code,
                    'slug' => $trans['slug'] ?? $data->code,
                    'description' => $trans['description'] ?? null,
                ]);
            }

            CategoryCreated::dispatch($category);

            return $category->load(['translations', 'parent', 'children']);
        });
    }
}
