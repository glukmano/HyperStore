<?php

declare(strict_types=1);

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Checkout\Services\CheckoutOwnershipService;
use Modules\Order\Contracts\OrderCancellationServiceInterface;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\Contracts\OrderOwnershipServiceInterface;
use Modules\Order\Contracts\OrderStateMachineServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\DTOs\OrderTransitionDTO;
use Modules\Order\Enums\OrderActorType;
use Modules\Order\Enums\StatusDimension;
use Modules\Order\Exceptions\OrderNotFoundException;
use Modules\Order\Http\Resources\OrderDiagnosticResource;
use Modules\Order\Http\Resources\OrderResource;
use Modules\Order\Models\Order;

Route::prefix('api/v1/orders')->group(function (): void {

    // 1. Create Order from ready Checkout
    Route::post('/', function (
        Request $request,
        ContextManager $contextManager,
        OrderCreationServiceInterface $creationService,
        CheckoutOwnershipService $checkoutOwnershipService
    ) {
        $tenantId = (int) $contextManager->getTenant()->getId();

        $validated = $request->validate([
            'checkout_id' => 'required_without:checkout_session_id|integer',
            'checkout_session_id' => 'required_without:checkout_id|integer',
        ]);

        $checkoutId = (int) ($validated['checkout_id'] ?? $validated['checkout_session_id']);

        /** @var CheckoutSession $checkout */
        $checkout = CheckoutSession::query()
            ->where('id', $checkoutId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $checkoutToken = $request->header('X-Checkout-Token')
            ?? $request->header('X-Guest-Token')
            ?? $request->header('X-Cart-Token');

        $checkoutOwnershipService->verifyOwnership(
            $checkout,
            is_string($checkoutToken) ? $checkoutToken : null
        );

        $idempotencyKey = $request->header('Idempotency-Key');

        $user = $request->user();
        $actorType = $user !== null ? OrderActorType::CUSTOMER : OrderActorType::GUEST;
        $actorId = $user !== null ? (int) $user->getAuthIdentifier() : null;

        $result = $creationService->createFromCheckout(new OrderCreationDTO(
            tenantId: $tenantId,
            checkoutId: $checkoutId,
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
            actorType: $actorType,
            actorId: $actorId,
        ));

        $orderResource = (new OrderResource($result->order))->toArray($request);

        $response = [
            'message' => $result->isReplay ? 'Order retrieved (replay)' : 'Order created successfully',
            'order' => $orderResource,
            'is_replay' => $result->isReplay,
        ];

        if ($result->guestAccessToken !== null) {
            $response['guest_access_token'] = $result->guestAccessToken;
        }

        return response()->json($response, $result->isReplay ? 200 : 201);
    });

    // 2. Retrieve Order (Customer / Guest)
    Route::get('/{identifier}', function (
        string $identifier,
        Request $request,
        ContextManager $contextManager,
        OrderOwnershipServiceInterface $ownershipService
    ) {
        $tenantId = (int) $contextManager->getTenant()->getId();

        /** @var Order|null $order */
        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($identifier) {
                if (is_numeric($identifier)) {
                    $query->where('id', (int) $identifier);
                } else {
                    $query->where('uuid', $identifier)
                        ->orWhere('order_number', $identifier);
                }
            })
            ->first();

        if ($order === null) {
            throw OrderNotFoundException::withIdentifier($identifier);
        }

        $guestToken = $request->header('X-Order-Token')
            ?? $request->header('X-Guest-Token');

        $ownershipService->verifyOwnership($order, is_string($guestToken) ? $guestToken : null);

        return new OrderResource($order);
    });

    // 3. Cancel Order (Customer / Guest)
    Route::post('/{identifier}/cancel', function (
        string $identifier,
        Request $request,
        ContextManager $contextManager,
        OrderOwnershipServiceInterface $ownershipService,
        OrderCancellationServiceInterface $cancellationService
    ) {
        $tenantId = (int) $contextManager->getTenant()->getId();

        /** @var Order|null $order */
        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($identifier) {
                if (is_numeric($identifier)) {
                    $query->where('id', (int) $identifier);
                } else {
                    $query->where('uuid', $identifier)
                        ->orWhere('order_number', $identifier);
                }
            })
            ->first();

        if ($order === null) {
            throw OrderNotFoundException::withIdentifier($identifier);
        }

        $guestToken = $request->header('X-Order-Token')
            ?? $request->header('X-Guest-Token');

        $ownershipService->verifyOwnership($order, is_string($guestToken) ? $guestToken : null);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        $actorType = $user !== null ? OrderActorType::CUSTOMER : OrderActorType::GUEST;
        $actorId = $user !== null ? (int) $user->getAuthIdentifier() : null;
        $idempotencyKey = $request->header('Idempotency-Key');

        $cancelledOrder = $cancellationService->cancel(
            order: $order,
            reason: $validated['reason'] ?? 'Customer cancellation',
            actorType: $actorType,
            actorId: $actorId,
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null
        );

        return response()->json([
            'message' => 'Order cancelled successfully',
            'order' => new OrderResource($cancelledOrder),
        ]);
    });
});

// Control Center Order Endpoints
Route::prefix('api/v1/control-center/orders')->middleware(['auth:sanctum'])->group(function (): void {

    Route::get('/', function (Request $request, ContextManager $contextManager) {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user?->can('order.view') && ! $user?->can('orders.view') && ! $user?->can('order.manage')) {
            abort(403, 'Unauthorized. Missing order.view permission.');
        }

        $tenantId = (int) $contextManager->getTenant()->getId();

        $query = Order::query()
            ->where('tenant_id', $tenantId)
            ->with('items')
            ->orderByDesc('id');

        if ($request->filled('order_status')) {
            $query->where('order_status', $request->input('order_status'));
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->input('payment_status'));
        }
        if ($request->filled('fulfillment_status')) {
            $query->where('fulfillment_status', $request->input('fulfillment_status'));
        }

        $orders = $query->paginate((int) $request->input('per_page', 25));

        return OrderDiagnosticResource::collection($orders);
    });

    Route::get('/{id}', function (int $id, Request $request, ContextManager $contextManager) {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user?->can('order.view') && ! $user?->can('orders.view') && ! $user?->can('order.manage')) {
            abort(403, 'Unauthorized. Missing order.view permission.');
        }

        $tenantId = (int) $contextManager->getTenant()->getId();

        /** @var Order $order */
        $order = Order::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['items', 'statusHistory'])
            ->firstOrFail();

        return new OrderDiagnosticResource($order);
    });

    Route::post('/{id}/transition', function (
        int $id,
        Request $request,
        ContextManager $contextManager,
        OrderStateMachineServiceInterface $stateMachine
    ) {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user?->can('order.manage')) {
            abort(403, 'Unauthorized. Missing order.manage permission.');
        }

        $tenantId = (int) $contextManager->getTenant()->getId();

        /** @var Order $order */
        $order = Order::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'dimension' => 'required|string|in:order',
            'from_status' => 'nullable|string|max:32',
            'to_status' => 'required|string|max:32',
            'reason' => 'nullable|string|max:255',
        ]);

        $transitioned = $stateMachine->transition($order, new OrderTransitionDTO(
            fromStatus: (string) ($validated['from_status'] ?? $order->order_status),
            toStatus: (string) $validated['to_status'],
            dimension: StatusDimension::ORDER,
            reason: $validated['reason'] ?? 'Staff operator transition',
            actorType: OrderActorType::STAFF,
            actorId: (int) $user->getAuthIdentifier(),
        ));

        return response()->json([
            'message' => 'Order transitioned successfully',
            'order' => new OrderDiagnosticResource($transitioned),
        ]);
    });

    Route::post('/{id}/cancel', function (
        int $id,
        Request $request,
        ContextManager $contextManager,
        OrderCancellationServiceInterface $cancellationService
    ) {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user?->can('order.cancel') && ! $user?->can('order.manage')) {
            abort(403, 'Unauthorized. Missing order.cancel permission.');
        }

        $tenantId = (int) $contextManager->getTenant()->getId();

        /** @var Order $order */
        $order = Order::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        $cancelled = $cancellationService->cancel(
            order: $order,
            reason: $validated['reason'],
            actorType: OrderActorType::STAFF,
            actorId: (int) $user->getAuthIdentifier(),
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null
        );

        return response()->json([
            'message' => 'Order cancelled by staff operator',
            'order' => new OrderDiagnosticResource($cancelled),
        ]);
    });
});
