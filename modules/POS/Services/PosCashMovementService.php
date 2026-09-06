<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use InvalidArgumentException;
use Modules\POS\Enums\CashMovementType;
use Modules\POS\Models\PosCashMovement;
use Modules\POS\Models\PosRegisterSession;

/**
 * Owner Delta §3: append-only, idempotent by (tenant_id, source_type,
 * source_uuid, movement_type). Expected cash is ALWAYS derived live from
 * SUM(amount_minor) over these five real movement types — never a cached
 * counter. amount_minor is stored as a signed delta (inflow positive,
 * outflow negative) so SUM directly gives the expected drawer balance.
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

        /** @var PosCashMovement|null $existing */
        $existing = PosCashMovement::where('tenant_id', $session->tenant_id)
            ->where('source_type', $sourceType)
            ->where('source_uuid', $sourceUuid)
            ->where('movement_type', $type->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $signedAmount = $type->isInflow() ? $magnitudeMinor : -$magnitudeMinor;

        return PosCashMovement::create([
            'tenant_id' => $session->tenant_id,
            'register_session_id' => $session->id,
            'movement_type' => $type->value,
            'amount_minor' => $signedAmount,
            'currency' => $session->currency,
            'reason' => $reason,
            'source_type' => $sourceType,
            'source_uuid' => $sourceUuid,
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    public function expectedCashMinor(PosRegisterSession $session): int
    {
        return (int) PosCashMovement::where('register_session_id', $session->id)->sum('amount_minor');
    }
}
