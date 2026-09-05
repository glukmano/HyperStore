<?php

declare(strict_types=1);

namespace Modules\Notifications;

use App\Core\Modular\ModuleServiceProvider;
use Modules\Notifications\Contracts\NotificationPreferenceGateInterface;
use Modules\Notifications\Services\NotificationPreferenceGate;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return base_path('modules/Notifications');
    }

    public function register(): void
    {
        $this->app->singleton(NotificationPreferenceGateInterface::class, NotificationPreferenceGate::class);
    }
}
