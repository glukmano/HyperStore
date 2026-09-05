<?php

declare(strict_types=1);

namespace Modules\Notifications\Contracts;

use App\Models\User;

/**
 * The one place that decides whether a channel is allowed for a given user
 * and notification type. Callers in Customers/Reviews/Messaging depend on
 * this contract only — never on each other, and never on a second gating
 * mechanism (one-way dependency: domain modules -> Notifications, never the
 * reverse).
 */
interface NotificationPreferenceGateInterface
{
    /**
     * @param  list<string>  $declaredChannels  the channels the Notification
     *                                          class itself declares via via()
     * @return list<string> the subset of $declaredChannels this user has not opted out of
     */
    public function resolveAllowedChannels(User $user, string $notificationType, array $declaredChannels): array;
}
