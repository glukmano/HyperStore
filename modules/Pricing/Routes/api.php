<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\Contracts\TaxCalculatorInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\DTOs\TaxContext;
use Modules\Pricing\Models\ExchangeRate;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\ValueObjects\MoneyValue;

Route::prefix('api/v1/pricing')->middleware(['api', 'auth:sanctum'])->group(function () {
    // 1. Price Books
    Route::get('price-books', function (Request $request) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);

        return response()->json(['data' => PriceBook::where('tenant_id', $tenantId)->get()]);
    });

    Route::post('price-books', function (Request $request) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);
        $validated = $request->validate([
            'name' => ['required', 'string'],
            'code' => ['required', 'string'],
            'currency' => ['required', 'string', 'size:3'],
            'priority' => ['nullable', 'integer'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $pb = PriceBook::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'currency' => strtoupper($validated['currency']),
            'priority' => $validated['priority'] ?? 0,
            'is_default' => (bool) ($validated['is_default'] ?? false),
            'status' => 'active',
        ]);

        return response()->json(['data' => $pb], 201);
    });

    // 2. Prices
    Route::get('prices', function (Request $request) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);

        return response()->json(['data' => Price::where('tenant_id', $tenantId)->with(['tierPrices', 'priceBook'])->get()]);
    });

    // 3. Resolve Price
    Route::post('resolve', function (Request $request, PriceResolverInterface $resolver) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);
        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'store_id' => ['nullable', 'integer'],
            'market_id' => ['nullable', 'integer'],
            'channel_id' => ['nullable', 'integer'],
            'customer_group_id' => ['nullable', 'integer'],
        ]);

        $item = new PricingItem(
            productId: (int) $validated['product_id'],
            variantId: isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            quantity: (int) ($validated['quantity'] ?? 1)
        );

        $context = new PricingContext(
            tenantId: $tenantId,
            currency: strtoupper($validated['currency']),
            storeId: isset($validated['store_id']) ? (int) $validated['store_id'] : null,
            marketId: isset($validated['market_id']) ? (int) $validated['market_id'] : null,
            channelId: isset($validated['channel_id']) ? (int) $validated['channel_id'] : null,
            customerGroupId: isset($validated['customer_group_id']) ? (int) $validated['customer_group_id'] : null
        );

        $result = $resolver->resolve($item, $context);

        if ($result === null) {
            return response()->json(['message' => 'Price not found for the given context.'], 404);
        }

        return response()->json([
            'data' => [
                'product_id' => $result->productId,
                'variant_id' => $result->variantId,
                'unit_price_minor' => $result->unitPrice->getMinorAmount(),
                'unit_price_formatted' => $result->unitPrice->format(),
                'compare_at_minor' => $result->compareAtPrice?->getMinorAmount(),
                'currency' => $result->unitPrice->getCurrencyCode(),
                'applied_price_book_id' => $result->appliedPriceBookId,
                'applied_tier_min' => $result->appliedTierMinQuantity,
                'explanation' => $result->appliedRules,
            ],
        ]);
    });

    // 4. Exchange Rates
    Route::get('exchange-rates', function (Request $request) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);

        return response()->json(['data' => ExchangeRate::where('tenant_id', $tenantId)->get()]);
    });

    Route::post('convert-currency', function (Request $request, CurrencyConversionInterface $converter) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer'],
            'source_currency' => ['required', 'string', 'size:3'],
            'target_currency' => ['required', 'string', 'size:3'],
        ]);

        $money = MoneyValue::fromMinor((int) $validated['amount_minor'], $validated['source_currency']);
        $converted = $converter->convert($money, $validated['target_currency'], $tenantId);

        return response()->json([
            'data' => [
                'source_amount_minor' => $money->getMinorAmount(),
                'source_currency' => $money->getCurrencyCode(),
                'converted_amount_minor' => $converted->getMinorAmount(),
                'converted_currency' => $converted->getCurrencyCode(),
                'converted_formatted' => $converted->format(),
            ],
        ]);
    });

    // 5. Tax Calculation
    Route::post('tax-calculate', function (Request $request, TaxCalculatorInterface $taxCalc) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer'],
            'currency' => ['required', 'string', 'size:3'],
            'tax_class_id' => ['required', 'integer'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'is_tax_inclusive' => ['nullable', 'boolean'],
        ]);

        $money = MoneyValue::fromMinor((int) $validated['amount_minor'], $validated['currency']);
        $context = new TaxContext(
            tenantId: $tenantId,
            countryCode: $validated['country_code'] ?? null,
            isTaxInclusive: (bool) ($validated['is_tax_inclusive'] ?? true)
        );

        $result = $taxCalc->calculate($money, (int) $validated['tax_class_id'], $context);

        return response()->json([
            'data' => [
                'net_minor' => $result->netAmount->getMinorAmount(),
                'tax_minor' => $result->taxAmount->getMinorAmount(),
                'gross_minor' => $result->grossAmount->getMinorAmount(),
                'applied_rates' => $result->appliedRates,
            ],
        ]);
    });
});
