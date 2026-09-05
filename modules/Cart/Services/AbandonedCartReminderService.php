<?php

declare(strict_types=1);

namespace Modules\Cart\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Models\AbandonedCartReminderLog;
use Modules\Cart\Models\Cart;
use Modules\Cart\Notifications\AbandonedCartReminder;
use Modules\Customers\Models\CustomerProfile;
use Modules\Notifications\Services\NotificationDispatchService;

/**
 * Owner Delta correction §15/§16: consent-gated (authenticated Customers
 * with marketing_opt_in only — no lawful signal exists for guest carts
 * today, so guests are never targeted), and race-safe via the
 * (tenant_id, cart_id, reminder_sequence) unique constraint plus an
 * immediate re-check of cart status before dispatch.
 */
final class AbandonedCartReminderService
{
    /**
     * @var array<int, int> reminder_sequence => hours since last cart update
     */
    private const array TIERS = [
        1 => 1,
        2 => 24,
        3 => 72,
    ];

    public function __construct(
        private readonly NotificationDispatchService $dispatchService,
    ) {}

    public function sendDueReminders(): int
    {
        $sent = 0;

        foreach (self::TIERS as $sequence => $hoursSinceUpdate) {
            $threshold = Carbon::now()->subHours($hoursSinceUpdate);

            $candidates = Cart::where('status', 'active')
                ->whereNotNull('user_id')
                ->where('updated_at', '<=', $threshold)
                ->whereDoesntHave('abandonedReminders', function ($q) use ($sequence): void {
                    $q->where('reminder_sequence', $sequence);
                })
                ->get();

            foreach ($candidates as $cart) {
                if ($this->sendReminderIfEligible($cart, $sequence)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    private function sendReminderIfEligible(Cart $cart, int $sequence): bool
    {
        return DB::transaction(function () use ($cart, $sequence): bool {
            /** @var Cart|null $freshCart */
            $freshCart = Cart::where('tenant_id', $cart->tenant_id)->lockForUpdate()->find($cart->id);
            if ($freshCart === null || $freshCart->status !== 'active') {
                // Converted/expired/locked concurrently — never send.
                return false;
            }

            if ($freshCart->user_id === null) {
                return false;
            }

            $profile = CustomerProfile::where('tenant_id', $freshCart->tenant_id)
                ->where('user_id', $freshCart->user_id)
                ->first();
            if ($profile === null || ! $profile->marketing_opt_in) {
                return false;
            }

            try {
                AbandonedCartReminderLog::create([
                    'tenant_id' => $freshCart->tenant_id,
                    'cart_id' => $freshCart->id,
                    'reminder_sequence' => $sequence,
                    'sent_at' => now(),
                ]);
            } catch (QueryException) {
                // Unique constraint violation: another process already sent
                // this exact reminder — race-safe no-op.
                return false;
            }

            $user = $freshCart->user;
            if ($user !== null) {
                $this->dispatchService->send($user, 'abandoned_cart_reminder', new AbandonedCartReminder($freshCart, $sequence));
            }

            return true;
        });
    }
}
