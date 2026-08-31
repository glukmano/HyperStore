<?php

declare(strict_types=1);

use App\Core\Context\ContextManager;
use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\CarrierCredential;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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

    // Rate quote endpoint (pure read-only)
    Route::post('rates/quote', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        $data = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
            'region_code' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'postal_code' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'integer'], // minor units
            'lines.*.unit_weight' => ['nullable', 'string'], // kg
            'lines.*.is_shippable' => ['nullable', 'boolean'],
            'lines.*.shipping_class_id' => ['nullable', 'integer'],
            'lines.*.inventory_source_id' => ['nullable', 'integer'],
        ]);

        $currency = $data['currency'] ?? 'CHF';
        $destination = new ShippingDestination(
            countryCode: $data['country_code'],
            regionCode: $data['region_code'] ?? null,
            city: $data['city'] ?? null,
            postalCode: $data['postal_code'] ?? null
        );

        $context = new ShippingContext(
            tenantId: $tenantId,
            storeId: null,
            marketId: null,
            channelId: null,
            currency: $currency
        );

        $lines = [];
        foreach ($data['lines'] as $l) {
            $lines[] = [
                'product_id' => (int) $l['product_id'],
                'variant_id' => isset($l['variant_id']) ? (int) $l['variant_id'] : null,
                'quantity' => (int) $l['quantity'],
                'unit_price' => MoneyValue::fromMinor((int) $l['unit_price'], $currency),
                'unit_weight' => isset($l['unit_weight']) ? Weight::of((string) $l['unit_weight'], 'kg') : Weight::zero(),
                'dimensions' => null,
                'shipping_class_id' => isset($l['shipping_class_id']) ? (int) $l['shipping_class_id'] : null,
                'is_shippable' => $l['is_shippable'] ?? true,
                'inventory_source_id' => isset($l['inventory_source_id']) ? (int) $l['inventory_source_id'] : null,
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
                    'promotion_discount' => $q->breakdown->promotionDiscount->getMinorAmount(),
                    'final_amount' => $q->breakdown->finalAmount->getMinorAmount(),
                ],
                'estimated_days_min' => $q->estimatedDaysMin,
                'estimated_days_max' => $q->estimatedDaysMax,
            ]),
        ]);
    });

    // Zones CRUD
    Route::get('zones', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.zones.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingZone::where('tenant_id', $tenantId)->with('rules')->get());
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

    // Methods CRUD
    Route::get('methods', function (Request $request) use ($getTenantId) {
        $tenantId = $getTenantId();
        if (! $request->user()?->can('shipping.methods.view') && ! $request->user()?->is_super_admin) {
            throw new AccessDeniedHttpException('Permission denied.');
        }

        return response()->json(ShippingMethod::where('tenant_id', $tenantId)->with('methodZones')->get());
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

        $cred = CarrierCredential::firstOrNew([
            'carrier_id' => $carrier->id,
            'environment' => $data['environment'],
        ]);
        $cred->setDecryptedCredentials($data['credentials']);
        $cred->save();

        return response()->json(['success' => true, 'message' => 'Credentials encrypted and stored successfully.']);
    });
});
