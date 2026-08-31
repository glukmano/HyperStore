<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\Api\V1;

use App\Core\Context\ContextManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Actions\CreateCategoryAction;
use Modules\Catalog\Contracts\CategoryHierarchyValidatorInterface;
use Modules\Catalog\DTOs\CategoryData;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\CategoryTranslation;

class CategoryApiController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
        private readonly CreateCategoryAction $createCategoryAction,
        private readonly CategoryHierarchyValidatorInterface $hierarchyValidator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) ($this->contextManager->getTenant()->getId() ?? $request->query('tenant_id', 1));

        $categories = Category::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with(['translations', 'children.translations'])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) ($this->contextManager->getTenant()->getId() ?? $request->input('tenant_id', 1));

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'translations' => ['required', 'array'],
            'translations.*.name' => ['required', 'string'],
            'translations.*.slug' => ['required', 'string'],
            'translations.*.description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', 'string', 'in:active,inactive,archived'],
            'sort_order' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $data = new CategoryData(
            tenantId: $tenantId,
            code: $validated['code'],
            translations: $validated['translations'],
            parentId: $validated['parent_id'] ?? null,
            status: $validated['status'] ?? 'active',
            sortOrder: (int) ($validated['sort_order'] ?? 0),
            metadata: $validated['metadata'] ?? null,
        );

        $category = $this->createCategoryAction->execute($data);

        return response()->json(['data' => $category], 201);
    }

    public function show(int $id): JsonResponse
    {
        $category = Category::with(['translations', 'parent.translations', 'children.translations'])->findOrFail($id);

        return response()->json(['data' => $category]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var Category $category */
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['sometimes', 'string', 'in:active,inactive,archived'],
            'sort_order' => ['sometimes', 'integer'],
            'translations' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('parent_id', $validated)) {
            $this->hierarchyValidator->assertNoCycle($category, $validated['parent_id']);
            $category->parent_id = $validated['parent_id'];
        }

        if (isset($validated['code'])) {
            $category->code = $validated['code'];
        }

        if (isset($validated['status'])) {
            $category->status = $validated['status'];
        }

        if (isset($validated['sort_order'])) {
            $category->sort_order = (int) $validated['sort_order'];
        }

        $category->save();

        if (isset($validated['translations'])) {
            foreach ($validated['translations'] as $locale => $trans) {
                CategoryTranslation::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'locale' => $locale,
                    ],
                    [
                        'name' => $trans['name'] ?? $category->code,
                        'slug' => $trans['slug'] ?? $category->code,
                        'description' => $trans['description'] ?? null,
                    ]
                );
            }
        }

        return response()->json(['data' => $category->load(['translations', 'parent', 'children'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        /** @var Category $category */
        $category = Category::findOrFail($id);
        $category->update(['status' => 'archived']);

        return response()->json(['message' => 'Category archived.']);
    }
}
