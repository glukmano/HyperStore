<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Enums\CashMovementType;
use Modules\POS\Enums\RegisterSessionStatus;
use Modules\POS\Exceptions\PosRegisterSessionException;
use Modules\POS\Models\PosRegister;
use Modules\POS\Models\PosRegisterSession;

/**
 * Owner Delta §1/§3: one active session per register is DB-enforced (a
 * partial unique index); the second-worker race is caught via
 * QueryException, never assumed away by an app-level check alone. The
 * opening_float PosCashMovement row and the session's opening_cash_minor
 * snapshot are written atomically in the same transaction — the movement
 * is authoritative, the session column is a fixed snapshot of it.
 */
final class PosRegisterSessionService
{
    public function __construct(
        private readonly PosRegisterContextResolver $contextResolver,
        private readonly PosCashierAuthorizationService $authorizationService,
        private readonly PosCashMovementService $cashMovementService,
    ) {}

    public function open(PosRegister $register, int $cashierUserId, string $currency, int $openingFloatMinor): PosRegisterSession
    {
        if (! $register->isActive()) {
            throw new PosRegisterSessionException("Register [{$register->id}] is not active.");
        }

        if (! $this->authorizationService->isAuthorizedForRegister($cashierUserId, $register)) {
            throw new PosRegisterSessionException("User [{$cashierUserId}] is not authorized for Register [{$register->id}].");
        }

        $context = $this->contextResolver->resolve($register);
        $this->contextResolver->assertCurrencyAllowed($context, $currency);

        if ($openingFloatMinor < 0) {
            throw new PosRegisterSessionException('Opening float must be non-negative.');
        }

        try {
            return DB::transaction(function () use ($register, $cashierUserId, $currency, $openingFloatMinor): PosRegisterSession {
                /** @var PosRegisterSession $session */
                $session = PosRegisterSession::create([
                    'tenant_id' => $register->tenant_id,
                    'register_id' => $register->id,
                    'cashier_user_id' => $cashierUserId,
                    'status' => RegisterSessionStatus::ACTIVE->value,
                    'currency' => $currency,
                    'opening_cash_minor' => $openingFloatMinor,
                    'opened_at' => now(),
                ]);

                $this->cashMovementService->record(
                    session: $session,
                    type: CashMovementType::OPENING_FLOAT,
                    magnitudeMinor: $openingFloatMinor,
                    sourceType: 'register_session_open',
                    sourceUuid: $session->uuid,
                    createdByUserId: $cashierUserId,
                    reason: 'Opening float'
                );

                return $session;
            });
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23505') {
                throw new PosRegisterSessionException("Register [{$register->id}] already has an active session.", 0, $e);
            }

            throw $e;
        }
    }

    public function close(PosRegisterSession $session, int $closingCashCountedMinor, int $actorUserId): PosRegisterSession
    {
        return DB::transaction(function () use ($session, $closingCashCountedMinor): PosRegisterSession {
            /** @var PosRegisterSession $locked */
            $locked = PosRegisterSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                return $locked;
            }

            $expectedMinor = $this->cashMovementService->expectedCashMinor($locked);
            $variance = $closingCashCountedMinor - $expectedMinor;

            $locked->update([
                'status' => RegisterSessionStatus::CLOSED->value,
                'closing_cash_counted_minor' => $closingCashCountedMinor,
                'closing_cash_expected_minor' => $expectedMinor,
                'closing_variance_minor' => $variance,
                'closed_at' => now(),
            ]);

            return $locked;
        });
    }
}
