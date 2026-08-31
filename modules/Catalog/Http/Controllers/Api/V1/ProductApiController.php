<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\Api\V1;

use App\Core\Context\ContextManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Catalog\Actions\ArchiveProductAction;
use Modules\Catalog\Actions\AssignAttributeValuesAction;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Actions\CreateVariantAction;
use Modules\Catalog\Actions\PublishProductToStoreAction;
use Modules\Catalog\Actions\UpdateProductAction;
use Modules\Catalog\DTOs\AttributeValueData;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\DTOs\StorePublicationData;
use Modules\Catalog\DTOs\VariantData;
use Modules\Catalog\Models\Product;

class ProductApiController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
        private readonly CreateProductAction $createProductAction,
        private readonly UpdateProductAction $updateProductAction,
        private readonly ArchiveProductAction $archiveProductAction,
        private readonly PublishProductToStoreAction $publishProductToStoreAction,
        private readonly CreateVariantAction $createVariantAction,
        private readonly AssignAttributeValuesAction $assignAttributeValuesAction,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) ($this->contextManager->getTenant()->getId() ?? $request->query('tenant_id', 1));

        $query = Product::query()
            ->where('tenant_id', $tenantId)
            ->with(['translations', 'categories', 'brand', 'attributeSet']);

        if ($request->filled('type')) {
            $query->where('product_type', $request->query('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where('status', '!=', 'archived');
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->query('brand_id'));
        }

        if ($request->filled('category_id')) {
            $query->whereHas('categories', fn ($q) => $q->where('category_id', $request->query('category_id')));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $perPage = min((int) $request->query('per_page', 25), 100);
        $products = $query->paginate($perPage);

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) ($this->contextManager->getTenant()->getId() ?? $request->input('tenant_id', 1));

        $validated = $request->validate([
            'product_type' => ['required', 'string'],
            'sku' => ['required', 'string', 'max:150'],
            'translations' => ['required', 'array'],
            'translations.*.name' => ['required', 'string'],
            'translations.*.short_description' => ['nullable', 'string'],
            'translations.*.description' => ['nullable', 'string'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'attribute_set_id' => ['nullable', 'integer', 'exists:attribute_sets,id'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'mpn' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:draft,active,inactive'],
            'category_ids' => ['nullable', 'array'],
            'primary_category_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $data = new ProductData(
            tenantId: $tenantId,
            productType: $validated['product_type'],
            sku: $validated['sku'],
            translations: $validated['translations'],
            brandId: $validated['brand_id'] ?? null,
            attributeSetId: $validated['attribute_set_id'] ?? null,
            barcode: $validated['barcode'] ?? null,
            mpn: $validated['mpn'] ?? null,
            status: $validated['status'] ?? 'draft',
            categoryIds: $validated['category_ids'] ?? [],
            primaryCategoryId: $validated['primary_category_id'] ?? null,
            metadata: $validated['metadata'] ?? null,
        );

        $product = $this->createProductAction->execute($data);

        return response()->json(['data' => $product], 201);
    }

    public function show(int $id): JsonResponse
    {
        /** @var Product $product */
        $product = Product::with([
            'translations',
            'categories.translations',
            'brand.translations',
            'attributeSet.groups',
            'variants.options.attribute.translations',
            'variants.options.option.translations',
            'attributeValues.attribute.translations',
            'attributeOptions.option.translations',
            'storeListings.translations',
            'storeListings.markets',
            'storeListings.channels',
        ])->findOrFail($id);

        return response()->json([
            'data' => $product,
            'capabilities' => $product->getTypeDefinition()->getCapabilities(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var Product $product */
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'product_type' => ['sometimes', 'string'],
            'sku' => ['sometimes', 'string', 'max:150'],
            'translations' => ['sometimes', 'array'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'attribute_set_id' => ['nullable', 'integer', 'exists:attribute_sets,id'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'mpn' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'in:draft,active,inactive,archived'],
            'category_ids' => ['nullable', 'array'],
            'primary_category_id' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        /** @var array<string, array{name: string, short_description: string|null, description: string|null}> $translations */
        $translations = is_array($validated['translations'] ?? null) ? $validated['translations'] : [];
        if (empty($translations)) {
            foreach ($product->translations as $trans) {
                $translations[$trans->locale] = [
                    'name' => $trans->name,
                    'short_description' => $trans->short_description,
                    'description' => $trans->description,
                ];
            }
        }

        $data = new ProductData(
            tenantId: $product->tenant_id,
            productType: $validated['product_type'] ?? $product->product_type,
            sku: $validated['sku'] ?? $product->sku,
            translations: $translations,
            brandId: array_key_exists('brand_id', $validated) ? $validated['brand_id'] : $product->brand_id,
            attributeSetId: array_key_exists('attribute_set_id', $validated) ? $validated['attribute_set_id'] : $product->attribute_set_id,
            barcode: $validated['barcode'] ?? $product->barcode,
            mpn: $validated['mpn'] ?? $product->mpn,
            status: $validated['status'] ?? $product->status,
            categoryIds: $validated['category_ids'] ?? $product->categories->pluck('id')->all(),
            primaryCategoryId: $validated['primary_category_id'] ?? null,
            metadata: $validated['metadata'] ?? $product->metadata,
        );

        $updated = $this->updateProductAction->execute($product, $data);

        return response()->json(['data' => $updated]);
    }

    public function destroy(int $id): JsonResponse
    {
        /** @var Product $product */
        $product = Product::findOrFail($id);

        $archived = $this->archiveProductAction->execute($product);

        return response()->json([
            'message' => 'Product retired and archived successfully.',
            'data' => $archived,
        ]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        /** @var Product $product */
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'status' => ['required', 'string', 'in:published,hidden,draft'],
            'translations' => ['required', 'array'],
            'translations.*.slug' => ['required', 'string'],
            'translations.*.name' => ['nullable', 'string'],
            'visibility' => ['nullable', 'string', 'in:visible,catalog_only,search_only,hidden'],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'market_ids' => ['nullable', 'array'],
            'channel_ids' => ['nullable', 'array'],
        ]);

        $data = new StorePublicationData(
            productId: $product->id,
            storeId: (int) $validated['store_id'],
            status: $validated['status'],
            translations: $validated['translations'],
            visibility: $validated['visibility'] ?? 'visible',
            isFeatured: (bool) ($validated['is_featured'] ?? false),
            sortOrder: (int) ($validated['sort_order'] ?? 0),
            marketIds: $validated['market_ids'] ?? [],
            channelIds: $validated['channel_ids'] ?? [],
        );

        $listing = $this->publishProductToStoreAction->execute($data);

        return response()->json(['data' => $listing]);
    }

    public function assignAttributes(Request $request, int $id): JsonResponse
    {
        /** @var Product $product */
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'attributes' => ['required', 'array'],
            'attributes.*.attribute_id' => ['required', 'integer', 'exists:attributes,id'],
            'attributes.*.text_value' => ['nullable', 'string'],
            'attributes.*.int_value' => ['nullable', 'integer'],
            'attributes.*.decimal_value' => ['nullable', 'numeric'],
            'attributes.*.boolean_value' => ['nullable', 'boolean'],
            'attributes.*.date_value' => ['nullable', 'date'],
            'attributes.*.datetime_value' => ['nullable', 'date'],
            'attributes.*.file_path' => ['nullable', 'string'],
            'attributes.*.option_ids' => ['nullable', 'array'],
            'attributes.*.json_value' => ['nullable', 'array'],
        ]);

        $values = [];
        foreach ($validated['attributes'] as $item) {
            $values[] = new AttributeValueData(
                attributeId: (int) $item['attribute_id'],
                textValue: $item['text_value'] ?? null,
                intValue: isset($item['int_value']) ? (int) $item['int_value'] : null,
                decimalValue: isset($item['decimal_value']) ? (float) $item['decimal_value'] : null,
                booleanValue: isset($item['boolean_value']) ? (bool) $item['boolean_value'] : null,
                dateValue: $item['date_value'] ?? null,
                datetimeValue: $item['datetime_value'] ?? null,
                filePath: $item['file_path'] ?? null,
                optionIds: $item['option_ids'] ?? null,
                jsonValue: $item['json_value'] ?? null,
            );
        }

        $updatedProduct = $this->assignAttributeValuesAction->execute($product, $values);

        return response()->json(['data' => $updatedProduct]);
    }

    public function createVariant(Request $request, int $id): JsonResponse
    {
        /** @var Product $product */
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:150'],
            'options' => ['required', 'array'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,inactive,archived'],
            'sort_order' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $data = new VariantData(
            productId: $product->id,
            sku: $validated['sku'],
            options: $validated['options'],
            barcode: $validated['barcode'] ?? null,
            status: $validated['status'] ?? 'active',
            sortOrder: (int) ($validated['sort_order'] ?? 0),
            metadata: $validated['metadata'] ?? null,
        );

        $variant = $this->createVariantAction->execute($data);

        return response()->json(['data' => $variant], 201);
    }
}
