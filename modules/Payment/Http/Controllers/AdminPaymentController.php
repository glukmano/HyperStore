<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Controllers;

use App\Core\Context\ContextManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Exceptions\PaymentNotFoundException;
use Modules\Payment\Http\Resources\PaymentResource;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentCancellationService;
use Modules\Payment\Services\PaymentCaptureService;
use Modules\Payment\Services\PaymentRefundService;

class AdminPaymentController extends Controller
{
    public function __construct(
        private readonly ContextManager $contextManager,
        private readonly PaymentCaptureService $captureService,
        private readonly PaymentRefundService $refundService,
        private readonly PaymentCancellationService $cancellationService
    ) {}

    public function show(string $uuid, Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['payment.view', 'payments.view', 'order.manage']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('uuid', $uuid)
            ->with('transactions')
            ->first();

        if ($payment === null) {
            throw PaymentNotFoundException::forUuid($uuid);
        }

        return (new PaymentResource($payment))->response();
    }

    public function capture(string $uuid, Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['payment.capture', 'payments.capture', 'order.manage']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        $validated = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'metadata' => 'nullable|array',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        $result = $this->captureService->capture(
            tenantId: $tenantId,
            paymentUuid: $uuid,
            amountMinor: (int) $validated['amount_minor'],
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
            metadata: $validated['metadata'] ?? []
        );

        return response()->json($result);
    }

    public function refund(string $uuid, Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['payment.refund', 'payments.refund', 'order.manage']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        $validated = $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'metadata' => 'nullable|array',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        $result = $this->refundService->refund(
            tenantId: $tenantId,
            paymentUuid: $uuid,
            amountMinor: (int) $validated['amount_minor'],
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
            metadata: $validated['metadata'] ?? []
        );

        return response()->json($result);
    }

    public function void(string $uuid, Request $request): JsonResponse
    {
        $this->authorizePermission($request, ['payment.cancel', 'payments.cancel', 'order.manage']);
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        $validated = $request->validate([
            'reason' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        $result = $this->cancellationService->cancel(
            tenantId: $tenantId,
            paymentUuid: $uuid,
            reason: $validated['reason'] ?? 'Admin void',
            idempotencyKey: is_string($idempotencyKey) ? $idempotencyKey : null,
            metadata: $validated['metadata'] ?? []
        );

        return response()->json($result);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authorizePermission(Request $request, array $permissions): void
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }

        abort(403, 'Unauthorized. Missing required payment permission.');
    }
}
