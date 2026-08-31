<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\Api\V1;

use App\Core\Context\ContextManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\BrandTranslation;

class BrandApiController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->contextManager->getTenant()->getId() ?? (int) $request->query('tenant_id');

        $brands = Brand::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with('translations')
            ->get();

        return response()->json(['data' => $brands]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->contextManager->getTenant()->getId() ?? (int) $request->input('tenant_id');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'translations' => ['required', 'array'],
            'translations.*.name' => ['required', 'string'],
            'translations.*.slug' => ['required', 'string'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.website' => ['nullable', 'url'],
            'status' => ['nullable', 'string', 'in:active,inactive,archived'],
        ]);

        /** @var Brand $brand */
        $brand = Brand::create([
            'tenant_id' => $tenantId,
            'code' => $validated['code'],
            'status' => $validated['status'] ?? 'active',
        ]);

        foreach ($validated['translations'] as $locale => $trans) {
            BrandTranslation::create([
                'brand_id' => $brand->id,
                'locale' => $locale,
                'name' => $trans['name'],
                'slug' => $trans['slug'],
                'description' => $trans['description'] ?? null,
                'website' => $trans['website'] ?? null,
            ]);
        }

        return response()->json(['data' => $brand->load('translations')], 201);
    }

    public function show(int $id): JsonResponse
    {
        $brand = Brand::with(['translations', 'products'])->findOrFail($id);

        return response()->json(['data' => $brand]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = Brand::findOrFail($id);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:active,inactive,archived'],
            'translations' => ['sometimes', 'array'],
        ]);

        $brand->update($validated);

        if (isset($validated['translations'])) {
            foreach ($validated['translations'] as $locale => $trans) {
                BrandTranslation::updateOrCreate(
                    [
                        'brand_id' => $brand->id,
                        'locale' => $locale,
                    ],
                    [
                        'name' => $trans['name'] ?? $brand->code,
                        'slug' => $trans['slug'] ?? $brand->code,
                        'description' => $trans['description'] ?? null,
                        'website' => $trans['website'] ?? null,
                    ]
                );
            }
        }

        return response()->json(['data' => $brand->load('translations')]);
    }

    public function destroy(int $id): JsonResponse
    {
        /** @var Brand $brand */
        $brand = Brand::findOrFail($id);
        $brand->update(['status' => 'archived']);

        return response()->json(['message' => 'Brand archived.']);
    }
}
