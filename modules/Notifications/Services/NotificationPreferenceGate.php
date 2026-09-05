<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Modules\Customers\Models\CustomerProfile;
use Modules\Notifications\Contracts\NotificationPreferenceGateInterface;

/**
 * Reads CustomerProfile.notification_preferences (JSONB, keyed by
 * notification type -> channel -> bool). A staff/vendor-staff/super-admin
 * user (no CustomerProfile row for this tenant) or a customer who has never
 * touched their preferences defaults to opted-in on every declared channel —
 * this is an opt-OUT model, never opt-in-by-default-false, so existing
 * behavior (every notification always sent) is unchanged until a user
 * explicitly disables a channel.
 */
final class NotificationPreferenceGate implements NotificationPreferenceGateInterface
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function resolveAllowedChannels(User $user, string $notificationType, array $declaredChannels): array
    {
        if (! $this->contextManager->hasTenant()) {
            return $declaredChannels;
        }

        $profile = CustomerProfile::query()
            ->where('tenant_id', (int) $this->contextManager->getTenant()->getId())
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null) {
            return $declaredChannels;
        }

        $preferences = $profile->notification_preferences ?? [];
        $typePreferences = $preferences[$notificationType] ?? [];

        return array_values(array_filter(
            $declaredChannels,
            fn (string $channel): bool => ($typePreferences[$channel] ?? true) === true,
        ));
    }
}
