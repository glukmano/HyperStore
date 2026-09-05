<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Customers\Models\CustomerProfile;
use Modules\Customers\Models\CustomerReferral;
use Modules\Customers\Models\CustomerReferralCode;
use Modules\Order\Models\Order;
use Modules\Promotions\Services\LoyaltyService;

/**
 * Peer-to-peer, non-monetary referral (ADR-0143) — deliberately distinct
 * from the commission-bearing Affiliate program. Owner Delta correction §13
 * governs the exact qualification policy implemented here.
 */
final class CustomerReferralService
{
    public const COOKIE_NAME = 'hs_ref_code';

    public function getOrCreateCode(CustomerProfile $profile): CustomerReferralCode
    {
        $existing = CustomerReferralCode::where('tenant_id', $profile->tenant_id)
            ->where('customer_profile_id', $profile->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return CustomerReferralCode::create([
            'tenant_id' => $profile->tenant_id,
            'customer_profile_id' => $profile->id,
            'code' => strtoupper(Str::random(8)),
        ]);
    }

    /**
     * Called once, at signup time (Illuminate\Auth\Events\Registered), for a
     * newly-registered Customer who arrived via a referral link/code.
     * Idempotent: a Customer already referred (or attempting self-referral)
     * is a safe no-op.
     */
    public function recordReferralSignup(CustomerProfile $referredProfile, string $code): ?CustomerReferral
    {
        $referralCode = CustomerReferralCode::where('tenant_id', $referredProfile->tenant_id)
            ->where('code', $code)
            ->first();
        if ($referralCode === null) {
            return null;
        }

        // Self-referral is blocked outright (not merely flagged) — a
        // Customer cannot refer themselves.
        if ((int) $referralCode->customer_profile_id === (int) $referredProfile->id) {
            return null;
        }

        return DB::transaction(function () use ($referredProfile, $referralCode): CustomerReferral {
            $existing = CustomerReferral::where('tenant_id', $referredProfile->tenant_id)
                ->where('referred_customer_profile_id', $referredProfile->id)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            return CustomerReferral::create([
                'tenant_id' => $referredProfile->tenant_id,
                'referrer_customer_profile_id' => $referralCode->customer_profile_id,
                'referred_customer_profile_id' => $referredProfile->id,
                'customer_referral_code_id' => $referralCode->id,
                'status' => 'pending',
            ]);
        });
    }

    /**
     * Called on OrderStatusChanged (payment=paid). Owner Delta correction
     * §13: only the referred Customer's FIRST qualifying paid Order
     * qualifies the referral, ever — idempotent, no duplicate reward.
     */
    public function qualifyOnOrderPaid(Order $order): void
    {
        if ($order->user_id === null) {
            return;
        }

        $referredProfile = CustomerProfile::where('tenant_id', $order->tenant_id)
            ->where('user_id', $order->user_id)
            ->first();
        if ($referredProfile === null) {
            return;
        }

        DB::transaction(function () use ($order, $referredProfile): void {
            /** @var CustomerReferral|null $referral */
            $referral = CustomerReferral::where('tenant_id', $order->tenant_id)
                ->where('referred_customer_profile_id', $referredProfile->id)
                ->lockForUpdate()
                ->first();

            if ($referral === null || $referral->status !== 'pending') {
                return;
            }

            // First qualifying paid Order only: is this the referred
            // Customer's first ever paid Order?
            $priorPaidOrders = Order::where('tenant_id', $order->tenant_id)
                ->where('user_id', $order->user_id)
                ->where('payment_status', 'paid')
                ->where('id', '!=', $order->id)
                ->exists();
            if ($priorPaidOrders) {
                return;
            }

            $referral->status = 'qualified';
            $referral->qualifying_order_id = $order->id;
            $referral->rewarded_at = CarbonImmutable::now();
            $referral->status = 'rewarded';
            $referral->save();

            /** @var CustomerProfile|null $referrer */
            $referrer = CustomerProfile::find($referral->referrer_customer_profile_id);
            if ($referrer !== null) {
                $loyaltyService = app(LoyaltyService::class);
                $program = $loyaltyService->activeProgram((int) $order->tenant_id);

                // Final Completion Delta §5: the reward amount is an explicit,
                // Tenant-scoped LoyaltyProgram configuration value — never a
                // hardcoded constant. No active program means no reward, exactly
                // like every other Loyalty operation (Owner Delta §10/§11).
                // The amount is read ONCE, here, at grant time, and frozen into
                // the append-only ledger entry — a later change to this setting
                // never alters an already-granted historical reward.
                if ($program !== null && $program->referral_reward_points > 0) {
                    $loyaltyService->earnPoints(
                        customerProfile: $referrer,
                        points: $program->referral_reward_points,
                        sourceType: 'customer_referral',
                        sourceUuid: 'customer_referral:'.$referral->id,
                    );
                }
            }
        });
    }
}
