<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Modules\Notifications\Contracts\HasNotificationChannels;
use Modules\Notifications\Contracts\NotificationPreferenceGateInterface;

/**
 * The one dispatch path domain listeners (Customers, Reviews, Messaging)
 * should use instead of calling `$user->notify()` directly — it consults
 * NotificationPreferenceGateInterface before sending, without requiring
 * every Notification class to know about preferences itself.
 */
final class NotificationDispatchService
{
    public function __construct(
        private readonly NotificationPreferenceGateInterface $gate,
    ) {}

    public function send(User $user, string $notificationType, Notification&HasNotificationChannels $notification): void
    {
        /** @var list<string> $declared */
        $declared = $notification->via($user);

        $allowed = $this->gate->resolveAllowedChannels($user, $notificationType, $declared);

        if ($allowed === []) {
            return;
        }

        NotificationFacade::sendNow($user, $notification, $allowed);
    }
}
