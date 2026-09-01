<?php

declare(strict_types=1);

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Context\ContextManager;
use App\Core\Context\Middleware\ResolveContextMiddleware;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Contracts\ProductShippingCapabilityResolverInterface;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\FulfillmentReadiness;
use Modules\Inventory\Models\InventorySource;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\CarrierCredential;
use Modules\Shipping\Models\CarrierService;
use Modules\Shipping\Models\PackageType;
use Modules\Shipping\Models\PickupLocation;
use Modules\Shipping\Models\ShippingClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingRateRule;
use Modules\Shipping\Models\ShippingRestriction;
use Modules\Shipping\Models\ShippingSourceMethodMapping;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneAssignment;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\Services\CarrierCredentialService;
use Modules\Shipping\ValueObjects\ProviderError;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateOutcome;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

Route::prefix('api/v1/shipping')->middleware(['api', 'auth:sanctum,web', ResolveContextMiddleware::class])->group(function () {
    $getTenantId = function (): int {
        $context = app(ContextManager::class);
        $tenant = $context->getTenant();
        if ($tenant === null) {
            throw new UnauthorizedHttpException('Tenant', 'Tenant context required.');
        }

        return (int) $tenant->getId();
    };

    // ==========================================
    // 1. PURE SHIPPING RATE QUOTE
    // ==========================================
    Route::post('rates/quote', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.rates.quote') && ! $request->user()?->can('shipping.rates.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $data = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'destination' => ['required', 'array'],
            'destination.country_code' => ['required', 'string', 'size:2'],
            'destination.region_code' => ['nullable', 'string'],
            'destination.city' => ['nullable', 'string'],
            'destination.postal_code' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer'],
            'lines.*.unit_weight' => ['nullable', 'string'],
            'lines.*.shipping_class_id' => ['nullable', 'integer'],
            'lines.*.inventory_source_id' => ['nullable', 'integer'],
        ]);

        $resolvedContext = app(ContextManager::class);
        $currency = $data['currency'] ?? ($resolvedContext->getCurrency()?->getCode() ?? 'CHF');

        $destination = new ShippingDestination(
            countryCode: $data['destination']['country_code'],
            regionCode: $data['destination']['region_code'] ?? null,
            city: $data['destination']['city'] ?? null,
            postalCode: $data['destination']['postal_code'] ?? null
        );

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

        $shippingLines = [];
        $fulfillmentLines = [];
        $hasPhysicalLines = false;

        foreach ($data['lines'] as $l) {
            $productId = (int) $l['product_id'];
            $variantId = isset($l['variant_id']) ? (int) $l['variant_id'] : null;

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

            // Inventory Source IDOR Check
            $sourceId = isset($l['inventory_source_id']) ? (int) $l['inventory_source_id'] : null;
            if ($sourceId !== null) {
                $source = InventorySource::where('tenant_id', $tenantId)->find($sourceId);
                if ($source === null) {
                    throw new NotFoundHttpException("Inventory source [{$sourceId}] not found for tenant.");
                }
            }

            // Catalog capability contract determines shippability (NO string comparison!)
            $isShippable = $capabilityResolver->requiresPhysicalShipping($product);
            $weight = isset($l['unit_weight']) ? Weight::of((string) $l['unit_weight'], 'kg') : Weight::zero();
            $unitPrice = MoneyValue::fromMinor((int) $l['unit_price'], $currency);

            if ($isShippable) {
                $hasPhysicalLines = true;
                $shippingLines[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => (int) $l['quantity'],
                    'unit_price' => $unitPrice,
                    'unit_weight' => $weight,
                    'dimensions' => null,
                    'shipping_class_id' => isset($l['shipping_class_id']) ? (int) $l['shipping_class_id'] : null,
                    'is_shippable' => true,
                    'inventory_source_id' => $sourceId,
                ];
            }

            $fulfillmentLines[] = new FulfillmentItemLine(
                productId: $productId,
                variantId: $variantId,
                quantity: (int) $l['quantity'],
                unitPrice: $unitPrice,
                unitWeight: $weight,
                isShippable: $isShippable
            );
        }

        // Digital / Non-physical only requests do NOT enter shipping rate engine
        if (! $hasPhysicalLines) {
            return response()->json([
                'tenant_id' => $tenantId,
                'currency' => $currency,
                'quotes' => [],
                'matched_zones' => [],
                'outcome' => ShippingRateOutcome::NO_SHIPPING_REQUIRED,
                'is_success' => true,
                'errors' => [],
                'warnings' => ['No physical items require shipping.'],
            ]);
        }

        // Trusted Server-Side Fulfillment Readiness Evaluation (Pure, Read-Only, Zero Stock Reservation)
        $hasUnfulfillableItems = false;
        /** @var FulfillmentPlanningServiceInterface $planner */
        $planner = app(FulfillmentPlanningServiceInterface::class);
        $plan = $planner->plan($tenantId, $fulfillmentLines, $context);
        foreach ($plan->groups as $group) {
            if ($group->readiness === FulfillmentReadiness::UNAVAILABLE) {
                $hasUnfulfillableItems = true;
                break;
            }
        }

        // ShippingRateRequest receives ONLY physical shippable lines
        $quoteRequest = new ShippingRateRequest(
            context: $context,
            destination: $destination,
            lines: $shippingLines,
            promotionBenefits: [],
            hasUnfulfillableItems: $hasUnfulfillableItems
        );

        /** @var ShippingRateEngineInterface $engine */
        $engine = app(ShippingRateEngineInterface::class);
        $result = $engine->calculateQuotes($quoteRequest);

        return response()->json([
            'outcome' => $result->outcome,
            'is_success' => $result->isSuccess(),
            'quotes' => $result->quotes->map(fn (ShippingRateQuote $q) => [
                'method_id' => $q->methodId,
                'method_code' => $q->methodCode,
                'title' => $q->title,
                'description' => $q->description,
                'amount_minor' => $q->amount->getMinorAmount(),
                'currency' => $q->amount->getCurrencyCode(),
                'breakdown' => [
                    'base_rate' => $q->breakdown->baseRate->getMinorAmount(),
                    'per_item_amount' => $q->breakdown->perItemAmount->getMinorAmount(),
                    'per_weight_amount' => $q->breakdown->perWeightAmount->getMinorAmount(),
                    'handling_fee' => $q->breakdown->handlingFee->getMinorAmount(),
                    'carrier_markup' => $q->breakdown->carrierMarkup->getMinorAmount(),
                    'promotion_discount' => $q->breakdown->promotionDiscount->getMinorAmount(),
                    'final_amount' => $q->breakdown->finalAmount->getMinorAmount(),
                ],
                'estimated_days_min' => $q->estimatedDaysMin,
                'estimated_days_max' => $q->estimatedDaysMax,
            ]),
            'errors' => array_map(fn (ProviderError $e) => $e->toArray(), $result->errors),
            'warnings' => $result->warnings,
        ]);
    });

    // ==========================================
    // 2. SHIPPING ZONES CRUD
    // ==========================================
    Route::get('zones', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingZone::where('tenant_id', $tenantId)->with(['rules', 'assignments'])->get());
    });

    Route::post('zones', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer'],
        ]);

        $zone = ShippingZone::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'priority' => $data['priority'] ?? 0,
            'status' => 'active',
        ]);

        return response()->json($zone, 201);
    });

    Route::get('zones/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $zone = ShippingZone::where('tenant_id', $tenantId)->with(['rules', 'assignments'])->findOrFail($id);

        return response()->json($zone);
    });

    Route::patch('zones/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'priority' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $zone->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json($zone);
    });

    Route::delete('zones/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail($id);
        $zone->delete();

        return response()->json(['deleted' => true]);
    });

    // Zone Rules
    Route::get('zones/{id}/rules', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json($zone->rules);
    });

    Route::post('zones/{id}/rules', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $request->validate([
            'rule_type' => ['required', 'string', 'in:country,region,postal_exact,postal_prefix,postal_range,broad_global'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'region_code' => ['nullable', 'string'],
            'postal_code_pattern' => ['nullable', 'string'],
            'postal_code_range_start' => ['nullable', 'string'],
            'postal_code_range_end' => ['nullable', 'string'],
            'is_exclusion' => ['nullable', 'boolean'],
        ]);

        $rule = ShippingZoneRule::create([
            'shipping_zone_id' => $zone->id,
            'rule_type' => $data['rule_type'],
            'country_code' => $data['country_code'] ?? null,
            'region_code' => $data['region_code'] ?? null,
            'postal_code_pattern' => $data['postal_code_pattern'] ?? null,
            'postal_code_range_start' => $data['postal_code_range_start'] ?? null,
            'postal_code_range_end' => $data['postal_code_range_end'] ?? null,
            'is_exclusion' => $data['is_exclusion'] ?? false,
        ]);

        return response()->json($rule, 201);
    });

    Route::delete('zones/{id}/rules/{ruleId}', function (Request $request, int $id, int $ruleId) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail($id);
        $rule = ShippingZoneRule::where('shipping_zone_id', $zone->id)->findOrFail($ruleId);
        $rule->delete();

        return response()->json(['deleted' => true]);
    });

    // Zone Assignments (With Strict Tenant-Ownership Validation for Store, Market, Channel)
    Route::get('zones/{id}/assignments', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json($zone->assignments);
    });

    Route::post('zones/{id}/assignments', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'store_id' => ['nullable', 'integer'],
            'market_id' => ['nullable', 'integer'],
            'channel_id' => ['nullable', 'integer'],
        ]);

        if (isset($data['store_id'])) {
            Store::where('tenant_id', $tenantId)->findOrFail((int) $data['store_id']);
        }
        if (isset($data['market_id'])) {
            Market::where('tenant_id', $tenantId)->findOrFail((int) $data['market_id']);
        }
        if (isset($data['channel_id'])) {
            $channelId = (int) $data['channel_id'];
            $channel = Channel::where('is_active', true)->find($channelId);
            if (! $channel) {
                throw new NotFoundHttpException("Channel [{$channelId}] not found or inactive.");
            }
            if (isset($data['store_id'])) {
                $storeId = (int) $data['store_id'];
                $isEligible = StoreChannel::where('store_id', $storeId)
                    ->where('channel_id', $channelId)
                    ->where('is_active', true)
                    ->exists();
                if (! $isEligible) {
                    throw new UnprocessableEntityHttpException("Channel [{$channelId}] is not enabled for store [{$storeId}].");
                }
            } else {
                $isEligible = StoreChannel::whereIn('store_id', Store::where('tenant_id', $tenantId)->select('id'))
                    ->where('channel_id', $channelId)
                    ->where('is_active', true)
                    ->exists();
                if (! $isEligible) {
                    throw new UnprocessableEntityHttpException("Channel [{$channelId}] is not enabled for any store in tenant.");
                }
            }
        }

        $assignment = ShippingZoneAssignment::create([
            'shipping_zone_id' => $zone->id,
            'store_id' => $data['store_id'] ?? null,
            'market_id' => $data['market_id'] ?? null,
            'channel_id' => $data['channel_id'] ?? null,
        ]);

        return response()->json($assignment, 201);
    });

    Route::delete('zones/{id}/assignments/{assignmentId}', function (Request $request, int $id, int $assignmentId) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail($id);
        $assignment = ShippingZoneAssignment::where('shipping_zone_id', $zone->id)->findOrFail($assignmentId);
        $assignment->delete();

        return response()->json(['deleted' => true]);
    });

    // ==========================================
    // 3. SHIPPING METHODS CRUD
    // ==========================================
    Route::get('methods', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.methods.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingMethod::where('tenant_id', $tenantId)->with(['methodZones', 'rateRules'])->get());
    });

    Route::post('methods', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.methods.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'rate_calculator_type' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'base_amount' => ['required', 'integer'],
            'handling_fee' => ['nullable', 'integer'],
            'priority' => ['nullable', 'integer'],
            'metadata' => ['nullable', 'array'],
        ]);

        $method = ShippingMethod::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'rate_calculator_type' => $data['rate_calculator_type'],
            'currency' => $data['currency'],
            'base_amount' => $data['base_amount'],
            'handling_fee' => $data['handling_fee'] ?? 0,
            'priority' => $data['priority'] ?? 0,
            'status' => 'active',
            'metadata' => $data['metadata'] ?? [],
        ]);

        return response()->json($method, 201);
    });

    Route::get('methods/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.methods.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $method = ShippingMethod::where('tenant_id', $tenantId)->with(['methodZones', 'rateRules'])->findOrFail($id);

        return response()->json($method);
    });

    Route::patch('methods/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.methods.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $method = ShippingMethod::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'base_amount' => ['nullable', 'integer'],
            'handling_fee' => ['nullable', 'integer'],
            'priority' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'metadata' => ['nullable', 'array'],
        ]);

        $method->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json($method);
    });

    Route::delete('methods/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.methods.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $method = ShippingMethod::where('tenant_id', $tenantId)->findOrFail($id);
        $method->delete();

        return response()->json(['deleted' => true]);
    });

    // Method Zone Assignments
    Route::post('methods/{id}/zones', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.methods.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $method = ShippingMethod::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate(['shipping_zone_id' => ['required', 'integer']]);

        $zone = ShippingZone::where('tenant_id', $tenantId)->findOrFail((int) $data['shipping_zone_id']);

        $assignment = ShippingMethodZone::firstOrCreate([
            'shipping_method_id' => $method->id,
            'shipping_zone_id' => $zone->id,
        ]);

        return response()->json($assignment, 201);
    });

    Route::delete('methods/{id}/zones/{zoneId}', function (Request $request, int $id, int $zoneId) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.methods.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $method = ShippingMethod::where('tenant_id', $tenantId)->findOrFail($id);
        $assignment = ShippingMethodZone::where('shipping_method_id', $method->id)->where('shipping_zone_id', $zoneId)->firstOrFail();
        $assignment->delete();

        return response()->json(['deleted' => true]);
    });

    // Rate Rules
    Route::get('methods/{id}/rate-rules', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.rates.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $method = ShippingMethod::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json($method->rateRules);
    });

    Route::post('methods/{id}/rate-rules', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.rates.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $method = ShippingMethod::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'priority' => ['nullable', 'integer'],
            'condition_type' => ['required', 'string'],
            'conditions_payload' => ['required', 'array'],
            'action_type' => ['required', 'string'],
            'action_payload' => ['required', 'array'],
            'stop_processing' => ['nullable', 'boolean'],
        ]);

        $rule = ShippingRateRule::create([
            'shipping_method_id' => $method->id,
            'priority' => $data['priority'] ?? 0,
            'condition_type' => $data['condition_type'],
            'conditions_payload' => $data['conditions_payload'],
            'action_type' => $data['action_type'],
            'action_payload' => $data['action_payload'],
            'stop_processing' => $data['stop_processing'] ?? false,
        ]);

        return response()->json($rule, 201);
    });

    Route::delete('methods/{id}/rate-rules/{ruleId}', function (Request $request, int $id, int $ruleId) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.rates.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $method = ShippingMethod::where('tenant_id', $tenantId)->findOrFail($id);
        $rule = ShippingRateRule::where('shipping_method_id', $method->id)->findOrFail($ruleId);
        $rule->delete();

        return response()->json(['deleted' => true]);
    });

    // ==========================================
    // 4. CARRIERS & SERVICES CRUD
    // ==========================================
    Route::get('carriers', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.carriers.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(Carrier::where('tenant_id', $tenantId)->with('services')->get());
    });

    Route::post('carriers', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.carriers.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'provider_code' => ['required', 'string'],
        ]);

        $carrier = Carrier::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'provider_code' => $data['provider_code'],
            'status' => 'active',
        ]);

        return response()->json($carrier, 201);
    });

    Route::get('carriers/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.carriers.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(Carrier::where('tenant_id', $tenantId)->with('services')->findOrFail($id));
    });

    Route::patch('carriers/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.carriers.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $carrier = Carrier::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $carrier->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json($carrier);
    });

    Route::delete('carriers/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.carriers.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $carrier = Carrier::where('tenant_id', $tenantId)->findOrFail($id);
        $carrier->delete();

        return response()->json(['deleted' => true]);
    });

    // Carrier Services
    Route::get('carriers/{id}/services', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.carriers.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $carrier = Carrier::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json($carrier->services);
    });

    Route::post('carriers/{id}/services', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.carriers.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $carrier = Carrier::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'transit_days_min' => ['nullable', 'integer'],
            'transit_days_max' => ['nullable', 'integer'],
            'markup_amount' => ['nullable', 'integer'],
            'markup_percentage' => ['nullable', 'numeric'],
        ]);

        $service = CarrierService::create([
            'carrier_id' => $carrier->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'transit_days_min' => $data['transit_days_min'] ?? 1,
            'transit_days_max' => $data['transit_days_max'] ?? 3,
            'markup_amount' => $data['markup_amount'] ?? 0,
            'markup_percentage' => $data['markup_percentage'] ?? 0,
            'status' => 'active',
        ]);

        return response()->json($service, 201);
    });

    Route::delete('carriers/{id}/services/{serviceId}', function (Request $request, int $id, int $serviceId) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.carriers.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $carrier = Carrier::where('tenant_id', $tenantId)->findOrFail($id);
        $service = CarrierService::where('carrier_id', $carrier->id)->findOrFail($serviceId);
        $service->delete();

        return response()->json(['deleted' => true]);
    });

    // Carrier Credentials (Write-only secrets)
    Route::post('carriers/{carrierId}/credentials', function (Request $request, int $carrierId) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.credentials.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        /** @var Carrier $carrier */
        $carrier = Carrier::where('tenant_id', $tenantId)->findOrFail($carrierId);

        $data = $request->validate([
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'credentials' => ['required', 'array'],
        ]);

        $service = app(CarrierCredentialService::class);
        $service->store($carrier, $data['environment'], $data['credentials']);

        return response()->json(['success' => true, 'message' => 'Credentials encrypted and stored securely.']);
    });

    Route::delete('carriers/{carrierId}/credentials/{environment}', function (Request $request, int $carrierId, string $environment) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.credentials.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $carrier = Carrier::where('tenant_id', $tenantId)->findOrFail($carrierId);
        $cred = CarrierCredential::where('carrier_id', $carrier->id)->where('environment', $environment)->firstOrFail();
        $cred->delete();

        return response()->json(['deleted' => true]);
    });

    // ==========================================
    // 5. SHIPPING CLASSES & PACKAGE TYPES
    // ==========================================
    Route::get('classes', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.classes.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingClass::where('tenant_id', $tenantId)->get());
    });

    Route::post('classes', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.classes.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $class = ShippingClass::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return response()->json($class, 201);
    });

    Route::get('classes/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.classes.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingClass::where('tenant_id', $tenantId)->findOrFail($id));
    });

    Route::patch('classes/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.classes.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $class = ShippingClass::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
        $class->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json($class);
    });

    Route::delete('classes/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.classes.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $class = ShippingClass::where('tenant_id', $tenantId)->findOrFail($id);
        $class->delete();

        return response()->json(['deleted' => true]);
    });

    // Package Types
    Route::get('package-types', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.package_types.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(PackageType::where('tenant_id', $tenantId)->get());
    });

    Route::post('package-types', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.package_types.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'max_weight_kg' => ['nullable', 'string'],
        ]);

        $type = PackageType::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'max_weight_kg' => $data['max_weight_kg'] ?? '30.0000',
            'status' => 'active',
        ]);

        return response()->json($type, 201);
    });

    Route::get('package-types/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.package_types.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(PackageType::where('tenant_id', $tenantId)->findOrFail($id));
    });

    Route::patch('package-types/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.package_types.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $type = PackageType::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'max_weight_kg' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);
        $type->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json($type);
    });

    Route::delete('package-types/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.package_types.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $type = PackageType::where('tenant_id', $tenantId)->findOrFail($id);
        $type->delete();

        return response()->json(['deleted' => true]);
    });

    // ==========================================
    // 6. PICKUP LOCATIONS & RESTRICTIONS & MAPPINGS
    // ==========================================
    Route::get('pickup-locations', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.pickup_locations.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(PickupLocation::where('tenant_id', $tenantId)->get());
    });

    Route::post('pickup-locations', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.pickup_locations.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'inventory_source_id' => ['nullable', 'integer'],
            'fee_amount' => ['nullable', 'integer'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        if (isset($data['inventory_source_id'])) {
            InventorySource::where('tenant_id', $tenantId)->findOrFail((int) $data['inventory_source_id']);
        }

        $loc = PickupLocation::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'name' => $data['name'],
            'inventory_source_id' => $data['inventory_source_id'] ?? null,
            'fee_amount' => $data['fee_amount'] ?? 0,
            'currency' => $data['currency'] ?? 'CHF',
            'status' => 'active',
        ]);

        return response()->json($loc, 201);
    });

    Route::get('pickup-locations/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.pickup_locations.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(PickupLocation::where('tenant_id', $tenantId)->findOrFail($id));
    });

    Route::patch('pickup-locations/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.pickup_locations.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $loc = PickupLocation::where('tenant_id', $tenantId)->findOrFail($id);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'fee_amount' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);
        $loc->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json($loc);
    });

    Route::delete('pickup-locations/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.pickup_locations.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $loc = PickupLocation::where('tenant_id', $tenantId)->findOrFail($id);
        $loc->delete();

        return response()->json(['deleted' => true]);
    });

    // Restrictions
    Route::get('restrictions', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.restrictions.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingRestriction::where('tenant_id', $tenantId)->get());
    });

    Route::post('restrictions', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.restrictions.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'shipping_method_id' => ['required', 'integer'],
            'restriction_type' => ['required', 'string'],
            'target_type' => ['nullable', 'string'],
            'target_id' => ['nullable', 'integer'],
            'shipping_zone_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string'],
        ]);

        ShippingMethod::where('tenant_id', $tenantId)->findOrFail((int) $data['shipping_method_id']);
        if (isset($data['shipping_zone_id'])) {
            ShippingZone::where('tenant_id', $tenantId)->findOrFail((int) $data['shipping_zone_id']);
        }

        $res = ShippingRestriction::create([
            'tenant_id' => $tenantId,
            'shipping_method_id' => $data['shipping_method_id'],
            'restriction_type' => $data['restriction_type'],
            'target_type' => $data['target_type'] ?? null,
            'target_id' => $data['target_id'] ?? null,
            'shipping_zone_id' => $data['shipping_zone_id'] ?? null,
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json($res, 201);
    });

    Route::get('restrictions/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.restrictions.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingRestriction::where('tenant_id', $tenantId)->findOrFail($id));
    });

    Route::delete('restrictions/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.restrictions.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $res = ShippingRestriction::where('tenant_id', $tenantId)->findOrFail($id);
        $res->delete();

        return response()->json(['deleted' => true]);
    });

    // Source Method Mappings
    Route::get('source-method-mappings', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.mappings.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingSourceMethodMapping::where('tenant_id', $tenantId)->get());
    });

    Route::post('source-method-mappings', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.mappings.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $data = $request->validate([
            'inventory_source_id' => ['required', 'integer'],
            'shipping_method_id' => ['required', 'integer'],
            'is_allowed' => ['required', 'boolean'],
        ]);

        InventorySource::where('tenant_id', $tenantId)->findOrFail((int) $data['inventory_source_id']);
        ShippingMethod::where('tenant_id', $tenantId)->findOrFail((int) $data['shipping_method_id']);

        $map = ShippingSourceMethodMapping::create([
            'tenant_id' => $tenantId,
            'inventory_source_id' => $data['inventory_source_id'],
            'shipping_method_id' => $data['shipping_method_id'],
            'is_allowed' => $data['is_allowed'],
        ]);

        return response()->json($map, 201);
    });

    Route::get('source-method-mappings/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.mappings.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingSourceMethodMapping::where('tenant_id', $tenantId)->findOrFail($id));
    });

    Route::delete('source-method-mappings/{id}', function (Request $request, int $id) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.mappings.manage') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }
        $map = ShippingSourceMethodMapping::where('tenant_id', $tenantId)->findOrFail($id);
        $map->delete();

        return response()->json(['deleted' => true]);
    });
});
