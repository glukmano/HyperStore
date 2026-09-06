<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cart\Models\CartLine;
use Modules\POS\Models\PosManualDiscountAuditLogEntry;
use Modules\POS\Models\PosRegisterSession;

/**
 * Owner Delta §10: 0 <= discount <= eligible line amount; server-side
 * bounds enforcement, never a client-suppliable replacement price.
 * Permission-gating (pos.discount.manual) is the caller's responsibility
 * (the Livewire action checks it before calling this service).
 */
final class PosManualDiscountService
{
    public function apply(CartLine $line, PosRegisterSession $session, int $amountMinor, string $reason, int $appliedByUserId): void
    {
        $eligibleLineAmountMinor = (int) round(((float) $line->quantity) * (int) ($line->display_unit_price_minor ?? 0));
        $boundedAmount = max(0, min($amountMinor, $eligibleLineAmountMinor));

        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Manual discount amount must be non-negative.');
        }

        DB::transaction(function () use ($line, $session, $boundedAmount, $reason, $appliedByUserId): void {
            $metadata = $line->metadata ?? [];
            $metadata['pos_manual_discount_minor'] = $boundedAmount;
            $metadata['pos_manual_discount_reason'] = $reason;
            $metadata['pos_manual_discount_applied_by_user_id'] = $appliedByUserId;
            $line->metadata = $metadata;
            $line->save();

            PosManualDiscountAuditLogEntry::create([
                'tenant_id' => $session->tenant_id,
                'cart_line_id' => $line->id,
                'order_item_id' => null,
                'register_session_id' => $session->id,
                'amount_minor' => $boundedAmount,
                'reason' => $reason,
                'applied_by_user_id' => $appliedByUserId,
            ]);
        });
    }
}
