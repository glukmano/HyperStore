<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\Api\V1;

use App\Core\Context\ContextManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\Attribute;
use Modules\Catalog\Models\AttributeOption;
use Modules\Catalog\Models\AttributeOptionTranslation;
use Modules\Catalog\Models\AttributeTranslation;

class AttributeApiController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->contextManager->getTenant()->getId() ?? (int) $request->query('tenant_id');

        $attributes = Attribute::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with(['translations', 'options.translations'])
            ->get();

        return response()->json(['data' => $attributes]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->contextManager->getTenant()->getId() ?? (int) $request->input('tenant_id');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string'],
            'translations' => ['required', 'array'],
            'translations.*.name' => ['required', 'string'],
            'is_filterable' => ['nullable', 'boolean'],
            'is_searchable' => ['nullable', 'boolean'],
            'is_comparable' => ['nullable', 'boolean'],
            'is_variant_driving' => ['nullable', 'boolean'],
            'options' => ['nullable', 'array'],
            'options.*.code' => ['required', 'string'],
            'options.*.label' => ['required', 'string'],
        ]);

        /** @var Attribute $attribute */
        $attribute = Attribute::create([
            'tenant_id' => $tenantId,
            'code' => $validated['code'],
            'type' => $validated['type'],
            'is_filterable' => (bool) ($validated['is_filterable'] ?? false),
            'is_searchable' => (bool) ($validated['is_searchable'] ?? false),
            'is_comparable' => (bool) ($validated['is_comparable'] ?? false),
            'is_variant_driving' => (bool) ($validated['is_variant_driving'] ?? false),
            'status' => 'active',
        ]);

        foreach ($validated['translations'] as $locale => $trans) {
            AttributeTranslation::create([
                'attribute_id' => $attribute->id,
                'locale' => $locale,
                'name' => $trans['name'],
                'description' => $trans['description'] ?? null,
                'unit_label' => $trans['unit_label'] ?? null,
            ]);
        }

        if (! empty($validated['options'])) {
            foreach ($validated['options'] as $idx => $opt) {
                /** @var AttributeOption $option */
                $option = AttributeOption::create([
                    'attribute_id' => $attribute->id,
                    'code' => $opt['code'],
                    'sort_order' => $idx,
                ]);

                AttributeOptionTranslation::create([
                    'attribute_option_id' => $option->id,
                    'locale' => app()->getLocale(),
                    'label' => $opt['label'],
                ]);
            }
        }

        return response()->json(['data' => $attribute->load(['translations', 'options.translations'])], 201);
    }

    public function show(int $id): JsonResponse
    {
        $attribute = Attribute::with(['translations', 'options.translations'])->findOrFail($id);

        return response()->json(['data' => $attribute]);
    }
}
