<?php

declare(strict_types=1);

use App\Core\Context\ContextManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\Models\CheckoutSession;

Route::prefix('v1/checkout')->group(function () {

    Route::post('/', function (Request $request, ContextManager $contextManager, CheckoutOrchestratorInterface $orchestrator) {
        $validated = $request->validate([
            'cart_id' => 'required|integer',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var Cart $cart */
        $cart = Cart::query()->where('id', $validated['cart_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');

        $session = $orchestrator->createFromCart($cart, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'id' => $session->id,
            'uuid' => $session->uuid,
            'state' => $session->state,
            'cart_id' => $session->cart_id,
            'evaluated_cart_version' => $session->evaluated_cart_version,
            'version' => $session->version,
            'expires_at' => $session->expires_at->toIso8601String(),
        ], 201);
    });

    Route::get('/{id}', function (int $id, ContextManager $contextManager) {
        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        return response()->json([
            'id' => $session->id,
            'uuid' => $session->uuid,
            'state' => $session->state,
            'customer_data' => $session->customer_data,
            'shipping_address' => $session->shipping_address,
            'billing_address' => $session->billing_address,
            'selected_shipping_quote' => $session->selected_shipping_quote,
            'pricing_snapshot' => $session->pricing_snapshot,
            'tax_snapshot' => $session->tax_snapshot,
            'promotion_snapshot' => $session->promotion_snapshot,
            'reservation_references' => $session->reservation_references,
            'evaluated_cart_version' => $session->evaluated_cart_version,
            'version' => $session->version,
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    });

    Route::post('/{id}/customer', function (int $id, Request $request, ContextManager $contextManager, CheckoutOrchestratorInterface $orchestrator) {
        $validated = $request->validate([
            'email' => 'required|email',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'phone' => 'nullable|string',
            'vat_id' => 'nullable|string',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');
        $custData = CheckoutCustomerData::fromArray($validated);

        $session = $orchestrator->setCustomerData($session, $custData, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'message' => 'Customer data saved',
            'state' => $session->state,
            'customer_data' => $session->customer_data,
        ]);
    });

    Route::post('/{id}/addresses', function (int $id, Request $request, ContextManager $contextManager, CheckoutOrchestratorInterface $orchestrator) {
        $validated = $request->validate([
            'shipping' => 'required|array',
            'shipping.recipient' => 'required|string',
            'shipping.street_lines' => 'required|array',
            'shipping.city' => 'required|string',
            'shipping.country_code' => 'required|string|size:2',
            'shipping.postal_code' => 'nullable|string',
            'shipping.region_code' => 'nullable|string',
            'shipping.phone' => 'nullable|string',
            'billing' => 'nullable|array',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $shippingAddr = CheckoutAddress::fromArray($validated['shipping']);
        $billingAddr = isset($validated['billing']) ? CheckoutAddress::fromArray($validated['billing']) : null;
        $idempotencyKey = $request->header('Idempotency-Key');

        $session = $orchestrator->setAddresses($session, $shippingAddr, $billingAddr, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'message' => 'Addresses saved',
            'state' => $session->state,
            'shipping_address' => $session->shipping_address,
            'billing_address' => $session->billing_address,
        ]);
    });

    Route::post('/{id}/shipping-selection', function (int $id, Request $request, ContextManager $contextManager, CheckoutOrchestratorInterface $orchestrator) {
        $validated = $request->validate([
            'method_id' => 'required|integer',
            'method_code' => 'required|string',
            'carrier_code' => 'nullable|string',
            'service_code' => 'nullable|string',
            'original_amount' => 'required|integer',
            'final_amount' => 'required|integer',
            'breakdown' => 'nullable|array',
        ]);

        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');

        $session = $orchestrator->selectShippingQuote($session, $validated, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'message' => 'Shipping selected',
            'state' => $session->state,
            'selected_shipping_quote' => $session->selected_shipping_quote,
        ]);
    });

    Route::post('/{id}/reserve', function (int $id, Request $request, ContextManager $contextManager, CheckoutOrchestratorInterface $orchestrator) {
        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');

        $session = $orchestrator->reserveInventory($session, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'message' => 'Inventory reserved',
            'state' => $session->state,
            'reservation_references' => $session->reservation_references,
        ]);
    });

    Route::post('/{id}/recalculate', function (int $id, Request $request, ContextManager $contextManager, CheckoutOrchestratorInterface $orchestrator) {
        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');

        $session = $orchestrator->recalculate($session, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'message' => 'Checkout recalculated',
            'pricing_snapshot' => $session->pricing_snapshot,
            'tax_snapshot' => $session->tax_snapshot,
            'promotion_snapshot' => $session->promotion_snapshot,
        ]);
    });

    Route::post('/{id}/ready', function (int $id, Request $request, ContextManager $contextManager, CheckoutOrchestratorInterface $orchestrator) {
        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');

        $readyResult = $orchestrator->markReadyForOrder($session, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'message' => 'Checkout ready for order',
            'result' => $readyResult->toArray(),
        ]);
    });

    Route::post('/{id}/cancel', function (int $id, Request $request, ContextManager $contextManager, CheckoutOrchestratorInterface $orchestrator) {
        $tenantId = (int) $contextManager->getTenant()->getId();
        /** @var CheckoutSession $session */
        $session = CheckoutSession::query()->where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');

        $orchestrator->cancel($session, is_string($idempotencyKey) ? $idempotencyKey : null);

        return response()->json([
            'message' => 'Checkout cancelled and reservations released',
        ]);
    });
});
