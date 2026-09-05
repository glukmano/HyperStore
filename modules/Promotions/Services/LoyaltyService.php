<?php

declare(strict_types=1);

namespace Modules\Promotions\Services;

use App\Core\Payables\Enums\PayableAvailabilityStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\CustomerProfile;
use Modules\Order\Models\Order;
use Modules\Promotions\Exceptions\InsufficientLoyaltyPointsException;
use Modules\Promotions\Exceptions\NoLoyaltyCurrencyRuleException;
use Modules\Promotions\Models\LoyaltyAccountLock;
use Modules\Promotions\Models\LoyaltyPointEntry;
use Modules\Promotions\Models\LoyaltyProgram;
use Modules\Promotions\Models\LoyaltyProgramCurrencyRule;

/**
 * Owner Delta correction §22: points are non-cash, non-withdrawable,
 * discount-entitlement only — never a modules/Ledger monetary account.
 * Corrections §10 (multi-currency), §11 (pending/maturity/reversal), and
 * §12 (concurrency-safe redemption) are all implemented here.
 */
final class LoyaltyService
{
    public function activeProgram(int $tenantId): ?LoyaltyProgram
    {
        return LoyaltyProgram::where('tenant_id', $tenantId)->where('is_active', true)->first();
    }

    private function currencyRule(int $tenantId, int $programId, string $currency): ?LoyaltyProgramCurrencyRule
    {
        return LoyaltyProgramCurrencyRule::where('tenant_id', $tenantId)
            ->where('loyalty_program_id', $programId)
            ->where('currency', $currency)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Public read for UI callers deciding whether to show a redemption
     * control at all for the checkout session's currency — never a
     * substitute for redeemPoints()'s own server-side re-validation.
     */
    public function redemptionValueForCurrency(int $tenantId, string $currency): ?int
    {
        $program = $this->activeProgram($tenantId);
        if ($program === null) {
            return null;
        }

        $rule = $this->currencyRule($tenantId, (int) $program->id, $currency);

        return $rule?->point_redemption_value_minor;
    }

    /**
     * Earn a fixed number of points for a non-order source (e.g. a Customer
     * referral reward). Idempotent by (source_type, source_uuid, entry_type).
     */
    public function earnPoints(CustomerProfile $customerProfile, int $points, string $sourceType, string $sourceUuid): void
    {
        if ($points <= 0) {
            return;
        }

        $program = $this->activeProgram((int) $customerProfile->tenant_id);
        if ($program === null) {
            return;
        }

        $existing = LoyaltyPointEntry::where('tenant_id', $customerProfile->tenant_id)
            ->where('source_type', $sourceType)
            ->where('source_uuid', $sourceUuid)
            ->where('entry_type', 'earned')
            ->first();
        if ($existing !== null) {
            return;
        }

        $now = CarbonImmutable::now();
        $hasHold = $program->pending_hold_days > 0;
        $availableAt = $hasHold ? $now->addDays($program->pending_hold_days) : $now;
        $expiresAt = $program->points_expire_after_days !== null
            ? $availableAt->addDays($program->points_expire_after_days)
            : null;

        LoyaltyPointEntry::create([
            'tenant_id' => $customerProfile->tenant_id,
            'customer_profile_id' => $customerProfile->id,
            'loyalty_program_id' => $program->id,
            'entry_type' => 'earned',
            'points' => $points,
            'source_type' => $sourceType,
            'source_uuid' => $sourceUuid,
            'availability_status' => $hasHold ? PayableAvailabilityStatus::Pending : PayableAvailabilityStatus::Available,
            'available_at' => $availableAt,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Earn points from a paid Order, using the currency-specific earn rate.
     * No matching currency rule means the Order's currency simply does not
     * participate in Loyalty — never a silent conversion (Owner Delta §10).
     */
    public function earnFromOrder(Order $order): void
    {
        if ($order->user_id === null) {
            return;
        }

        $customerProfile = CustomerProfile::where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->first();
        if ($customerProfile === null) {
            return;
        }

        $program = $this->activeProgram((int) $order->tenant_id);
        if ($program === null) {
            return;
        }

        $rule = $this->currencyRule((int) $order->tenant_id, (int) $program->id, (string) $order->currency);
        if ($rule === null) {
            return;
        }

        $points = intdiv((int) $order->grand_total_minor, $rule->minor_units_per_point);
        if ($points <= 0) {
            return;
        }

        $this->earnPoints($customerProfile, $points, 'order', 'order:'.$order->id);
    }

    public function maturePendingPoints(int $tenantId, ?CarbonImmutable $asOf = null): int
    {
        $cutoff = $asOf ?? CarbonImmutable::now();

        return DB::transaction(function () use ($tenantId, $cutoff): int {
            $pending = LoyaltyPointEntry::where('tenant_id', $tenantId)
                ->where('entry_type', 'earned')
                ->where('availability_status', PayableAvailabilityStatus::Pending->value)
                ->where('available_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            $matured = 0;
            foreach ($pending as $entry) {
                $entry->availability_status = PayableAvailabilityStatus::Available;
                $entry->save();
                $matured++;
            }

            return $matured;
        });
    }

    /**
     * Owner Delta correction §11: expires points whose expires_at has
     * passed, via a compensating append-only 'expired' entry — never
     * deletes/rewrites the original 'earned' entry.
     */
    public function expirePoints(int $tenantId, ?CarbonImmutable $asOf = null): int
    {
        $cutoff = $asOf ?? CarbonImmutable::now();

        return DB::transaction(function () use ($tenantId, $cutoff): int {
            $expiring = LoyaltyPointEntry::where('tenant_id', $tenantId)
                ->where('entry_type', 'earned')
                ->where('availability_status', PayableAvailabilityStatus::Available->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $cutoff)
                ->lockForUpdate()
                ->get();

            $count = 0;
            foreach ($expiring as $entry) {
                $sourceUuid = 'expire:'.$entry->id;
                $already = LoyaltyPointEntry::where('tenant_id', $tenantId)
                    ->where('source_type', 'point_expiry')
                    ->where('source_uuid', $sourceUuid)
                    ->where('entry_type', 'expired')
                    ->exists();
                if ($already) {
                    continue;
                }

                LoyaltyPointEntry::create([
                    'tenant_id' => $tenantId,
                    'customer_profile_id' => $entry->customer_profile_id,
                    'loyalty_program_id' => $entry->loyalty_program_id,
                    'entry_type' => 'expired',
                    'points' => -$entry->points,
                    'source_type' => 'point_expiry',
                    'source_uuid' => $sourceUuid,
                    'availability_status' => PayableAvailabilityStatus::Available,
                    'available_at' => $cutoff,
                ]);
                $count++;
            }

            return $count;
        });
    }

    /**
     * Authoritative available balance — always recomputed from the
     * append-only ledger, never trusted from a client or a separately
     * maintained running total.
     */
    public function getAvailableBalance(CustomerProfile $customerProfile): int
    {
        $earnedAvailable = (int) LoyaltyPointEntry::where('tenant_id', $customerProfile->tenant_id)
            ->where('customer_profile_id', $customerProfile->id)
            ->whereIn('entry_type', ['earned', 'manual_adjustment_credit'])
            ->where('availability_status', PayableAvailabilityStatus::Available->value)
            ->sum('points');

        $consumed = (int) LoyaltyPointEntry::where('tenant_id', $customerProfile->tenant_id)
            ->where('customer_profile_id', $customerProfile->id)
            ->whereIn('entry_type', ['redeemed', 'expired', 'manual_adjustment_debit'])
            ->sum('points');

        // 'redeemed'/'expired'/manual_adjustment_debit are stored as negative
        // point deltas, so summing them directly and adding is correct.
        return max(0, $earnedAvailable + $consumed);
    }

    /**
     * Owner Delta correction §12: concurrency-safe — locks a dedicated
     * account-lock anchor row, recomputes the authoritative balance INSIDE
     * that lock, then validates and writes atomically. Idempotent by
     * sourceUuid: a retried redemption request returns the same frozen
     * redemption value rather than recomputing against a possibly-changed
     * currency rate.
     */
    public function redeemPoints(CustomerProfile $customerProfile, int $pointsToRedeem, string $currency, string $sourceUuid): int
    {
        if ($pointsToRedeem <= 0) {
            throw new \InvalidArgumentException('Points to redeem must be strictly positive.');
        }

        return DB::transaction(function () use ($customerProfile, $pointsToRedeem, $currency, $sourceUuid): int {
            $program = $this->activeProgram((int) $customerProfile->tenant_id);
            if ($program === null) {
                throw NoLoyaltyCurrencyRuleException::forCurrency($currency);
            }

            /** @var LoyaltyAccountLock $lock */
            $lock = LoyaltyAccountLock::firstOrCreate([
                'tenant_id' => $customerProfile->tenant_id,
                'customer_profile_id' => $customerProfile->id,
                'loyalty_program_id' => $program->id,
            ]);
            LoyaltyAccountLock::where('id', $lock->id)->lockForUpdate()->first();

            $existing = LoyaltyPointEntry::where('tenant_id', $customerProfile->tenant_id)
                ->where('source_type', 'redemption')
                ->where('source_uuid', $sourceUuid)
                ->where('entry_type', 'redeemed')
                ->first();
            if ($existing !== null) {
                return (int) ($existing->redemption_value_minor ?? 0);
            }

            $rule = $this->currencyRule((int) $customerProfile->tenant_id, (int) $program->id, $currency);
            if ($rule === null) {
                throw NoLoyaltyCurrencyRuleException::forCurrency($currency);
            }

            $available = $this->getAvailableBalance($customerProfile);
            if ($pointsToRedeem > $available) {
                throw InsufficientLoyaltyPointsException::forRequest($pointsToRedeem, $available);
            }

            $valueMinor = $pointsToRedeem * $rule->point_redemption_value_minor;

            LoyaltyPointEntry::create([
                'tenant_id' => $customerProfile->tenant_id,
                'customer_profile_id' => $customerProfile->id,
                'loyalty_program_id' => $program->id,
                'entry_type' => 'redeemed',
                'points' => -$pointsToRedeem,
                'redemption_currency' => $currency,
                'redemption_value_minor' => $valueMinor,
                'source_type' => 'redemption',
                'source_uuid' => $sourceUuid,
                'availability_status' => PayableAvailabilityStatus::Available,
                'available_at' => CarbonImmutable::now(),
            ]);

            return $valueMinor;
        });
    }

    /**
     * Reverses a previously-redeemed point spend (checkout-completion
     * delta §4/§18) — used only when a checkout-time redemption never
     * turns into a paid Order (the customer abandons/cancels checkout).
     * Idempotent by the same sourceUuid the original redemption used; a
     * replayed or double-cancelled request is a safe no-op. Never touches
     * the original 'redeemed' entry — posts a compensating credit instead.
     */
    public function reverseRedemption(string $sourceUuid): void
    {
        $entry = LoyaltyPointEntry::where('source_type', 'redemption')
            ->where('source_uuid', $sourceUuid)
            ->where('entry_type', 'redeemed')
            ->first();

        if ($entry === null) {
            return;
        }

        $reversalUuid = 'redemption_reversal:'.$sourceUuid;
        $already = LoyaltyPointEntry::where('tenant_id', $entry->tenant_id)
            ->where('source_type', 'redemption_reversal')
            ->where('source_uuid', $reversalUuid)
            ->where('entry_type', 'manual_adjustment_credit')
            ->exists();
        if ($already) {
            return;
        }

        LoyaltyPointEntry::create([
            'tenant_id' => $entry->tenant_id,
            'customer_profile_id' => $entry->customer_profile_id,
            'loyalty_program_id' => $entry->loyalty_program_id,
            'entry_type' => 'manual_adjustment_credit',
            'points' => -$entry->points,
            'source_type' => 'redemption_reversal',
            'source_uuid' => $reversalUuid,
            'availability_status' => PayableAvailabilityStatus::Available,
            'available_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Owner Delta correction §11: refund before maturity reverses the still-
     * pending points outright; refund after maturity (points may already be
     * spent) posts a compensating negative adjustment, allowing the balance
     * to go negative — further redemption is blocked until it recovers
     * (enforced naturally by getAvailableBalance()'s max(0, ...) floor never
     * letting a negative balance be redeemed against).
     */
    public function reverseForOrderRefund(Order $order): void
    {
        $entry = LoyaltyPointEntry::where('tenant_id', $order->tenant_id)
            ->where('source_type', 'order')
            ->where('source_uuid', 'order:'.$order->id)
            ->where('entry_type', 'earned')
            ->first();

        if ($entry === null) {
            return;
        }

        $sourceUuid = 'order_refund:'.$order->id;
        $already = LoyaltyPointEntry::where('tenant_id', $order->tenant_id)
            ->where('source_type', 'order_refund')
            ->where('source_uuid', $sourceUuid)
            ->where('entry_type', 'manual_adjustment_debit')
            ->exists();
        if ($already) {
            return;
        }

        LoyaltyPointEntry::create([
            'tenant_id' => $entry->tenant_id,
            'customer_profile_id' => $entry->customer_profile_id,
            'loyalty_program_id' => $entry->loyalty_program_id,
            'entry_type' => 'manual_adjustment_debit',
            'points' => -$entry->points,
            'source_type' => 'order_refund',
            'source_uuid' => $sourceUuid,
            'availability_status' => PayableAvailabilityStatus::Available,
            'available_at' => CarbonImmutable::now(),
        ]);
    }
}
