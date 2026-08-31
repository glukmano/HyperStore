<?php

declare(strict_types=1);

use App\Core\Context\ContextManager;
use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\DTOs\FulfillmentGroup;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\Weight;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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

    // Pure Fulfillment Planning
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
            'items.*.is_shippable' => ['nullable', 'boolean'],
        ]);

        $currency = $data['currency'] ?? 'CHF';
        $context = new ShippingContext(tenantId: $tenantId, currency: $currency);

        $lines = [];
        foreach ($data['items'] as $item) {
            $lines[] = new FulfillmentItemLine(
                productId: (int) $item['product_id'],
                variantId: isset($item['variant_id']) ? (int) $item['variant_id'] : null,
                quantity: (int) $item['quantity'],
                unitPrice: MoneyValue::fromMinor((int) $item['unit_price'], $currency),
                unitWeight: isset($item['unit_weight']) ? Weight::of((string) $item['unit_weight'], 'kg') : Weight::zero(),
                isShippable: $item['is_shippable'] ?? true
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
                'split_reason' => $g->splitReason,
                'items_count' => count($g->items),
                'packages_count' => count($g->packages),
            ], $plan->groups),
        ]);
    });
});
