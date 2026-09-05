<?php

declare(strict_types=1);

namespace Modules\Notifications\Contracts;

/**
 * Laravel's own Notification base class does not declare via() as part of
 * its type contract (subclasses define it by convention, called via
 * method_exists/duck-typing at runtime) — this interface makes that
 * contract explicit and statically checkable for anything passed through
 * NotificationDispatchService.
 */
interface HasNotificationChannels
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array;
}
