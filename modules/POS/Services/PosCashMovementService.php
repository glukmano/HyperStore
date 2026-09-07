<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\POS\Enums\CashMovementType;
use Modules\POS\Enums\RegisterSessionStatus;
use Modules\POS\Exceptions\PosRegisterSessionClosedException;
use Modules\POS\Models\PosCashMovement;
use Modules\POS\Models\PosRegisterSession;

/**
 * Owner Delta §3: append-only, idempotent by (tenant_id, source_type,
 * source_uuid, movement_type). Expected cash is ALWAYS derived live from
 * SUM(amount_minor) over these five real movement types — never a cached
 * counter. amount_minor is stored as a signed delta (inflow positive,
 * outflow negative) so SUM directly gives the expected drawer balance.
 *
 * Pre-Production Readiness gate: record() locks the SAME RegisterSession
 * row that PosRegisterSessionService::close() locks, and re-checks
 * status === active under that lock before writing — this is the sole
 * serialization point between "record a movement" and "close the drawer",
 * proven race-free under real PostgreSQL concurrency. Whichever operation
 * acquires the row lock first wins; a movement attempt that loses the race
 * against an already-committed close() sees status=closed and is rejected,
 * never silently recorded after the drawer was reconciled.
 */
final class PosCashMovementService
{
    public function record(
        PosRegisterSession $session,
        CashMovementType $type,
        int $magnitudeMinor,
        string $sourceType,
        string $sourceUuid,
        int $createdByUserId,
        ?string $reason = null
    ): PosCashMovement {
        if ($magnitudeMinor < 0) {
            throw new InvalidArgumentException('Cash movement magnitude must be non-negative; sign is derived from movement type.');
        }

        return DB::transaction(function () use ($session, $type, $magnitudeMinor, $sourceType, $sourceUuid, $createdByUserId, $reason): PosCashMovement {
            /** @var PosRegisterSession $lockedSession */
            $lockedSession = PosRegisterSession::query()->where('id', $session->id)->lockForUpdate()->firstOrFail();

            /** @var PosCashMovement|null $existing */
            $existing = PosCashMovement::where('tenant_id', $lockedSession->tenant_id)
                ->where('source_type', $sourceType)
                ->where('source_uuid', $sourceUuid)
                ->where('movement_type', $type->value)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($lockedSession->status !== RegisterSessionStatus::ACTIVE) {
                throw new PosRegisterSessionClosedException(
                    "RegisterSession [{$lockedSession->id}] is already closed — cash movements cannot be recorded against a reconciled drawer."
                );
            }

            $signedAmount = $type->isInflow() ? $magnitudeMinor : -$magnitudeMinor;

            return PosCashMovement::create([
                'tenant_id' => $lockedSession->tenant_id,
                'register_session_id' => $lockedSession->id,
                'movement_type' => $type->value,
                'amount_minor' => $signedAmount,
                'currency' => $lockedSession->currency,
                'reason' => $reason,
                'source_type' => $sourceType,
                'source_uuid' => $sourceUuid,
                'created_by_user_id' => $createdByUserId,
            ]);
        });
    }

    public function expectedCashMinor(PosRegisterSession $session): int
    {
        return (int) PosCashMovement::where('register_session_id', $session->id)->sum('amount_minor');
    }
}
