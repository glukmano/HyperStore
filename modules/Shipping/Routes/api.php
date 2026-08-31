<?php

declare(strict_types=1);

use App\Core\Context\ContextManager;
use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Inventory\Models\InventorySource;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\PackageType;
use Modules\Shipping\Models\PickupLocation;
use Modules\Shipping\Models\ShippingClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Services\CarrierCredentialService;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

Route::prefix('api/v1/shipping')->middleware(['api', 'auth:sanctum,web', ResolveContextMiddleware::class])->group(function () {
    $getTenantId = function (): int {
        $context = app(ContextManager::class);
        $tenant = $context->getTenant();
        if ($tenant === null) {
            throw new UnauthorizedHttpException('Tenant', 'Tenant context required.');
        }

        return (int) $tenant->getId();
    };

    // 1. Pure Shipping Rate Quote
    Route::post('rates/quote', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.rates.quote') && ! $request->user()?->is_super_admin) {
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

        $lines = [];
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

            $isShippable = $product->product_type !== 'digital' && $product->product_type !== 'service';

            $lines[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => (int) $l['quantity'],
                'unit_price' => MoneyValue::fromMinor((int) $l['unit_price'], $currency),
                'unit_weight' => isset($l['unit_weight']) ? Weight::of((string) $l['unit_weight'], 'kg') : Weight::zero(),
                'dimensions' => null,
                'shipping_class_id' => isset($l['shipping_class_id']) ? (int) $l['shipping_class_id'] : null,
                'is_shippable' => $isShippable,
                'inventory_source_id' => $sourceId,
            ];
        }

        $quoteRequest = new ShippingRateRequest(
            context: $context,
            destination: $destination,
            lines: $lines
        );

        /** @var ShippingRateEngineInterface $engine */
        $engine = app(ShippingRateEngineInterface::class);
        $quotes = $engine->calculateQuotes($quoteRequest);

        return response()->json([
            'quotes' => $quotes->map(fn (ShippingRateQuote $q) => [
                'method_id' => $q->methodId,
                'method_code' => $q->methodCode,
                'title' => $q->title,
                'description' => $q->description,
                'amount_minor' => $q->amount->getMinorAmount(),
                'currency' => $q->amount->getCurrencyCode(),
                'breakdown' => [
                    'base_rate' => $q->breakdown->baseRate->getMinorAmount(),
                    'handling_fee' => $q->breakdown->handlingFee->getMinorAmount(),
                    'carrier_markup' => $q->breakdown->carrierMarkup->getMinorAmount(),
                    'promotion_discount' => $q->breakdown->promotionDiscount->getMinorAmount(),
                    'final_amount' => $q->breakdown->finalAmount->getMinorAmount(),
                ],
                'estimated_days_min' => $q->estimatedDaysMin,
                'estimated_days_max' => $q->estimatedDaysMax,
            ]),
        ]);
    });

    // 2. Shipping Zones CRUD
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

    // 3. Shipping Methods CRUD
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
        ]);

        return response()->json($method, 201);
    });

    // 4. Carrier Credentials (Write-only secrets)
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

    // 5. Shipping Classes & Package Types
    Route::get('classes', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();

        return response()->json(ShippingClass::where('tenant_id', $tenantId)->get());
    });

    Route::get('package-types', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();

        return response()->json(PackageType::where('tenant_id', $tenantId)->get());
    });

    // 6. Pickup Locations
    Route::get('pickup-locations', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();

        return response()->json(PickupLocation::where('tenant_id', $tenantId)->get());
    });
});
