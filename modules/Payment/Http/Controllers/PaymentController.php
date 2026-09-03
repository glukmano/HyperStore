<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Controllers;

use App\Core\Context\ContextManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Order\Contracts\OrderOwnershipServiceInterface;
use Modules\Order\Exceptions\OrderAccessDeniedException;
use Modules\Order\Models\Order;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Exceptions\PaymentAccessDeniedException;
use Modules\Payment\Exceptions\PaymentNotFoundException;
use Modules\Payment\Http\Resources\PaymentResource;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentInitiationService;

class PaymentController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
        private readonly OrderOwnershipServiceInterface $ownershipService,
        private readonly PaymentInitiationService $initiationService
    ) {}

    public function initiate(string $orderIdentifier, Request $request): JsonResponse
    {
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        /** @var Order|null $order */
        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($orderIdentifier) {
                $query->where('uuid', $orderIdentifier)
                    ->orWhere('order_number', $orderIdentifier);
            })
            ->first();

        if ($order === null) {
            throw PaymentNotFoundException::forOrder($orderIdentifier);
        }

        $guestToken = $request->header('X-Order-Token') ?? $request->header('X-Guest-Token');
        try {
            $this->ownershipService->verifyOwnership($order, is_string($guestToken) ? $guestToken : null);
        } catch (OrderAccessDeniedException) {
            throw PaymentAccessDeniedException::denied();
        }

        $validated = $request->validate([
            'amount_minor' => 'required|integer|min:0',
            'currency' => 'required|string|size:3',
            'provider_code' => 'nullable|string|max:64',
            'payment_method_type' => 'nullable|string|max:64',
            'payment_method_reference' => 'nullable|string|max:255',
            'capture_immediately' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        $dto = new InitiatePaymentDTO(
            tenantId: $tenantId,
            orderId: $order->id,
            amountMinor: (int) $validated['amount_minor'],
            currency: (string) $validated['currency'],
            providerCode: $validated['provider_code'] ?? null,
            paymentMethodType: $validated['payment_method_type'] ?? null,
            paymentMethodReference: $validated['payment_method_reference'] ?? null,
            captureImmediately: (bool) ($validated['capture_immediately'] ?? true),
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
            metadata: $validated['metadata'] ?? []
        );

        $result = $this->initiationService->initiatePayment($dto);

        return response()->json($result, 201);
    }

    public function show(string $orderIdentifier, Request $request): JsonResponse
    {
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        /** @var Order|null $order */
        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($orderIdentifier) {
                $query->where('uuid', $orderIdentifier)
                    ->orWhere('order_number', $orderIdentifier);
            })
            ->first();

        if ($order === null) {
            throw PaymentNotFoundException::forOrder($orderIdentifier);
        }

        $guestToken = $request->header('X-Order-Token') ?? $request->header('X-Guest-Token');
        try {
            $this->ownershipService->verifyOwnership($order, is_string($guestToken) ? $guestToken : null);
        } catch (OrderAccessDeniedException) {
            throw PaymentAccessDeniedException::denied();
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('order_id', $order->id)
            ->with(['transactions', 'order'])
            ->first();

        if ($payment === null) {
            throw PaymentNotFoundException::forUuid($orderIdentifier);
        }

        return (new PaymentResource($payment))->response();
    }
}
