<?php

declare(strict_types=1);

use App\Core\Context\ContextManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;

Route::prefix('v1/cart')->group(function () {

    Route::get('/', function (Request $request, ContextManager $contextManager, CartServiceInterface $cartService) {
        $tenantId = (int) $contextManager->getTenant()->getId();
        $storeId = $contextManager->hasStore() ? (int) $contextManager->getStore()->getId() : (int) ($request->header('X-Store-Id') ?? 0);
        $marketId = $contextManager->hasMarket() ? (int) $contextManager->getMarket()->getId() : (int) ($request->header('X-Market-Id') ?? 0);
        $channelId = $contextManager->hasChannel() ? (int) $contextManager->getChannel()->getId() : (int) ($request->header('X-Channel-Id') ?? 0);
        $currency = $contextManager->hasCurrency() ? (string) $contextManager->getCurrency()->getCode() : (string) $request->header('X-Currency', 'USD');
        $userId = $contextManager->hasUser() ? (int) $contextManager->getUser()->getId() : null;
        $guestToken = $request->header('X-Guest-Token');

        $ctx = new CartContext(
            tenantId: $tenantId,
            storeId: $storeId,
            marketId: $marketId,
            channelId: $channelId,
            currency: $currency,
            userId: $userId,
            guestToken: is_string($guestToken) ? $guestToken : null
        );

        $cart = $cartService->getOrCreateActiveCart($ctx);

        return response()->json([
            'id' => $cart->id,
            'uuid' => $cart->uuid,
            'currency' => $cart->currency,
            'status' => $cart->status,
            'coupon_code' => $cart->coupon_code,
            'version' => $cart->version,
            'lines_count' => $cart->lines->count(),
            'lines' => array_map(function (CartLine $l): array {
                return [
                    'id' => $l->id,
                    'product_id' => $l->product_id,
                    'variant_id' => $l->variant_id,
                    'quantity' => $l->quantity,
                    'options' => $l->options,
                    'customizations' => $l->customizations,
                    'display_unit_price_minor' => $l->display_unit_price_minor,
                    'display_currency' => $l->display_currency,
                    'is_price_stale' => (bool) $l->is_price_stale,
                ];
            }, $cart->lines->all()),
        ]);
    });

    Route::post('/lines', function (Request $request, ContextManager $contextManager, CartServiceInterface $cartService) {
        $validated = $request->validate([
            'cart_id' => 'required|integer',
            'product_id' => 'required|integer',
            'variant_id' => 'nullable|integer',
            'quantity' => 'required|numeric',
            'options' => 'nullable|array',
            'customizations' => 'nullable|array',
            'expected_version' => 'nullable|integer',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var Cart $cart */
        $cart = Cart::query()->where('id', $validated['cart_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $itemData = new CartLineItemData(
            productId: (int) $validated['product_id'],
            variantId: isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
            quantity: CartQuantity::fromString((string) $validated['quantity']),
            options: (array) ($validated['options'] ?? []),
            customizations: (array) ($validated['customizations'] ?? [])
        );

        $line = $cartService->addLine($cart, $itemData, isset($validated['expected_version']) ? (int) $validated['expected_version'] : null);

        return response()->json([
            'line' => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'quantity' => $line->quantity,
            ],
            'cart_version' => $cart->fresh()?->version,
        ], 201);
    });

    Route::patch('/lines/{id}', function (int $id, Request $request, ContextManager $contextManager, CartServiceInterface $cartService) {
        $validated = $request->validate([
            'cart_id' => 'required|integer',
            'quantity' => 'required|numeric',
            'expected_version' => 'nullable|integer',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var Cart $cart */
        $cart = Cart::query()->where('id', $validated['cart_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $line = $cartService->updateQuantity(
            $cart,
            $id,
            CartQuantity::fromString((string) $validated['quantity']),
            isset($validated['expected_version']) ? (int) $validated['expected_version'] : null
        );

        return response()->json([
            'line' => [
                'id' => $line->id,
                'quantity' => $line->quantity,
            ],
            'cart_version' => $cart->fresh()?->version,
        ]);
    });

    Route::delete('/lines/{id}', function (int $id, Request $request, ContextManager $contextManager, CartServiceInterface $cartService) {
        $validated = $request->validate([
            'cart_id' => 'required|integer',
            'expected_version' => 'nullable|integer',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var Cart $cart */
        $cart = Cart::query()->where('id', $validated['cart_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $cartService->removeLine($cart, $id, isset($validated['expected_version']) ? (int) $validated['expected_version'] : null);

        return response()->json([
            'message' => 'Line removed',
            'cart_version' => $cart->fresh()?->version,
        ]);
    });

    Route::post('/coupon', function (Request $request, ContextManager $contextManager, CartServiceInterface $cartService) {
        $validated = $request->validate([
            'cart_id' => 'required|integer',
            'coupon_code' => 'required|string',
            'expected_version' => 'nullable|integer',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var Cart $cart */
        $cart = Cart::query()->where('id', $validated['cart_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $cart = $cartService->applyCoupon($cart, $validated['coupon_code'], isset($validated['expected_version']) ? (int) $validated['expected_version'] : null);

        return response()->json([
            'coupon_code' => $cart->coupon_code,
            'cart_version' => $cart->version,
        ]);
    });

    Route::delete('/coupon', function (Request $request, ContextManager $contextManager, CartServiceInterface $cartService) {
        $validated = $request->validate([
            'cart_id' => 'required|integer',
            'expected_version' => 'nullable|integer',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var Cart $cart */
        $cart = Cart::query()->where('id', $validated['cart_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $cart = $cartService->removeCoupon($cart, isset($validated['expected_version']) ? (int) $validated['expected_version'] : null);

        return response()->json([
            'message' => 'Coupon removed',
            'cart_version' => $cart->version,
        ]);
    });

    Route::post('/merge', function (Request $request, ContextManager $contextManager, CartServiceInterface $cartService) {
        $validated = $request->validate([
            'guest_cart_id' => 'required|integer',
            'customer_cart_id' => 'required|integer',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var Cart $guestCart */
        $guestCart = Cart::query()->where('id', $validated['guest_cart_id'])->where('tenant_id', $tenantId)->firstOrFail();
        /** @var Cart $customerCart */
        $customerCart = Cart::query()->where('id', $validated['customer_cart_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $merged = $cartService->mergeGuestCart($guestCart, $customerCart);

        return response()->json([
            'cart_id' => $merged->id,
            'lines_count' => $merged->lines->count(),
            'cart_version' => $merged->version,
        ]);
    });
});
