<?php

declare(strict_types=1);

use App\Core\Context\ContextManager;
use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Contracts\ProductShippingCapabilityResolverInterface;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\DTOs\FulfillmentGroup;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\Models\FulfillmentSourceConfiguration;
use Modules\Fulfillment\Models\FulfillmentStrategy;
use Modules\Inventory\Models\InventorySource;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\Weight;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

Route::prefix('api/v1/fulfillment')->middleware(['api', 'auth:sanctum,web', ResolveContextMiddleware::class])->group(function () {
    $getTenantId = function (): int {
        $context = app(ContextManager::class);
        $tenant = $context->getTenant();
        if ($tenant === null) {
            throw new UnauthorizedHttpException('Tenant', 'Tenant context required.');
        }

        return (int) $tenant->getId();
    };

    // 1. Pure Fulfillment Planning
    Route::post('plan', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('fulfillment.plan') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $data = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer'],
            'items.*.unit_weight' => ['nullable', 'string'],
        ]);

        $resolvedContext = app(ContextManager::class);
        $currency = $data['currency'] ?? ($resolvedContext->getCurrency()?->getCode() ?? 'CHF');

        $store = $resolvedContext->getStore()?->getId();
        $market = $resolvedContext->getMarket()?->getId();
        $channel = $resolvedContext->getChannel()?->getId();
        $context = new ShippingContext(
            tenantId: $tenantId,
            currency: $currency,
            storeId: $store !== null ? (int) $store : null,
            marketId: $market !== null ? (int) $market : null,
            channelId: $channel !== null ? (int) $channel : null
        );

        $capabilityResolver = app(ProductShippingCapabilityResolverInterface::class);

        $lines = [];
        foreach ($data['items'] as $item) {
            $productId = (int) $item['product_id'];
            $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : null;

            // Product & Variant Tenant Ownership Check
            $product = Product::where('tenant_id', $tenantId)->find($productId);
            if ($product === null) {
                throw new NotFoundHttpException("Product [{$productId}] not found for tenant.");
            }
            if ($variantId !== null) {
                $variant = ProductVariant::where('tenant_id', $tenantId)->where('product_id', $productId)->find($variantId);
                if ($variant === null) {
                    throw new NotFoundHttpException("Variant [{$variantId}] not found for product.");
                }
            }

            // Catalog capability contract determines shippability (NO string comparison!)
            $isShippable = $capabilityResolver->requiresPhysicalShipping($product);

            $lines[] = new FulfillmentItemLine(
                productId: $productId,
                variantId: $variantId,
                quantity: (int) $item['quantity'],
                unitPrice: MoneyValue::fromMinor((int) $item['unit_price'], $currency),
                unitWeight: isset($item['unit_weight']) ? Weight::of((string) $item['unit_weight'], 'kg') : Weight::zero(),
                isShippable: $isShippable
            );
        }

        /** @var FulfillmentPlanningServiceInterface $planner */
        $planner = app(FulfillmentPlanningServiceInterface::class);
        $plan = $planner->plan($tenantId, $lines, $context);

        return response()->json([
            'tenant_id' => $plan->tenantId,
            'has_splits' => $plan->hasSplits,
            'groups' => array_map(fn (FulfillmentGroup $g) => [
                'group_key' => $g->groupKey,
                'fulfillment_mode' => $g->fulfillmentMode,
                'inventory_source_id' => $g->inventorySourceId,
                'warehouse_id' => $g->warehouseId,
                'is_shippable' => $g->isShippable,
                'readiness' => $g->readiness,
                'split_reason' => $g->splitReason,
                'items_count' => count($g->items),
                'packages_count' => count($g->packages),
            ], $plan->groups),
        ]);
    });

    // 2. Fulfillment Source Configurations CRUD
    Route::get('source-configurations', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('fulfillment.sources.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(FulfillmentSourceConfiguration::where('tenant_id', $tenantId)->get());
    });

    Route::post('source-configurations', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('fulfillment.sources.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $data = $request->validate([
            'inventory_source_id' => ['required', 'integer'],
            'fulfillment_mode' => ['required', 'string', 'in:own_stock,dropship,3pl,non_physical'],
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer'],
            'lead_time_days' => ['nullable', 'integer'],
        ]);

        InventorySource::where('tenant_id', $tenantId)->findOrFail((int) $data['inventory_source_id']);

        $config = FulfillmentSourceConfiguration::create([
            'tenant_id' => $tenantId,
            'inventory_source_id' => $data['inventory_source_id'],
            'fulfillment_mode' => $data['fulfillment_mode'],
            'priority' => $data['priority'] ?? 0,
            'status' => 'active',
        ]);

        return response()->json($config, 201);
    });

    Route::delete('source-configurations/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('fulfillment.sources.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $config = FulfillmentSourceConfiguration::where('tenant_id', $tenantId)->findOrFail($id);
        $config->delete();

        return response()->json(['deleted' => true]);
    });

    // 3. Fulfillment Strategies CRUD
    Route::get('strategies', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('fulfillment.strategies.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(FulfillmentStrategy::where('tenant_id', $tenantId)->get());
    });

    Route::post('strategies', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('fulfillment.strategies.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $data = $request->validate([
            'strategy_type' => ['required', 'string'],
        ]);

        $strat = FulfillmentStrategy::create([
            'tenant_id' => $tenantId,
            'strategy_type' => $data['strategy_type'],
            'is_default' => true,
        ]);

        return response()->json($strat, 201);
    });

    Route::delete('strategies/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('fulfillment.strategies.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $strat = FulfillmentStrategy::where('tenant_id', $tenantId)->findOrFail($id);
        $strat->delete();

        return response()->json(['deleted' => true]);
    });
});
