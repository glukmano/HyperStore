<?php

declare(strict_types=1);

namespace Modules\Customers\Listeners;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Modules\Customers\Services\CustomerProfileService;
use Modules\Customers\Services\CustomerReferralService;

/**
 * Listens to Laravel's own Registered event (no change to
 * RegisteredUserController needed) — reads the referral-code cookie set
 * when the visitor followed a Customer's referral link, and records the
 * (pending) referral. A failure here must never break registration.
 */
final class CaptureReferralSignupListener
{
    public function __construct(
        private readonly CustomerProfileService $profileService,
        private readonly CustomerReferralService $referralService,
    ) {}

    public function handle(Registered $event): void
    {
        $code = request()->cookie(CustomerReferralService::COOKIE_NAME);
        if (! is_string($code) || $code === '') {
            return;
        }

        if (! app(ContextManager::class)->hasTenant()) {
            return;
        }

        if (! $event->user instanceof User) {
            return;
        }

        try {
            $profile = $this->profileService->firstOrCreateFor($event->user);
            $this->referralService->recordReferralSignup($profile, $code);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
