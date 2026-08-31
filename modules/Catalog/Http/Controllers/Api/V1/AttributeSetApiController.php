<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\Api\V1;

use App\Core\Context\ContextManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Models\AttributeGroup;
use Modules\Catalog\Models\AttributeSet;

class AttributeSetApiController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) ($this->contextManager->getTenant()->getId() ?? $request->query('tenant_id', 1));

        $sets = AttributeSet::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with(['groups', 'attributes.translations'])
            ->get();

        return response()->json(['data' => $sets]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) ($this->contextManager->getTenant()->getId() ?? $request->input('tenant_id', 1));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100'],
            'groups' => ['nullable', 'array'],
            'groups.*.name' => ['required', 'string'],
            'attributes' => ['nullable', 'array'],
            'attributes.*.attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            'attributes.*.attribute_group_id' => ['nullable', 'integer'],
            'attributes.*.is_required' => ['nullable', 'boolean'],
        ]);

        /** @var AttributeSet $set */
        $set = AttributeSet::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'status' => 'active',
        ]);

        if (! empty($validated['groups'])) {
            foreach ($validated['groups'] as $idx => $grp) {
                AttributeGroup::create([
                    'attribute_set_id' => $set->id,
                    'name' => $grp['name'],
                    'sort_order' => $idx,
                ]);
            }
        }

        if (! empty($validated['attributes'])) {
            $syncData = [];
            foreach ($validated['attributes'] as $idx => $attr) {
                $syncData[$attr['attribute_id']] = [
                    'attribute_group_id' => $attr['attribute_group_id'] ?? null,
                    'is_required' => (bool) ($attr['is_required'] ?? false),
                    'sort_order' => $idx,
                ];
            }
            $set->attributes()->sync($syncData);
        }

        return response()->json(['data' => $set->load(['groups', 'attributes.translations'])], 201);
    }

    public function show(int $id): JsonResponse
    {
        $set = AttributeSet::with(['groups', 'attributes.translations'])->findOrFail($id);

        return response()->json(['data' => $set]);
    }

    public function destroy(int $id): JsonResponse
    {
        /** @var AttributeSet $set */
        $set = AttributeSet::findOrFail($id);
        $set->update(['status' => 'archived']);

        return response()->json(['message' => 'Attribute Set archived.']);
    }
}
