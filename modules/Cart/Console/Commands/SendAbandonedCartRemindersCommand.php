<?php

declare(strict_types=1);

namespace Modules\Cart\Console\Commands;

use Illuminate\Console\Command;
use Modules\Cart\Services\AbandonedCartReminderService;

class SendAbandonedCartRemindersCommand extends Command
{
    protected $signature = 'marketing:send-abandoned-cart-reminders';

    protected $description = 'Sends abandoned-cart reminder emails to consenting authenticated Customers whose cart is still active past each reminder tier threshold.';

    public function handle(AbandonedCartReminderService $service): int
    {
        $sent = $service->sendDueReminders();
        $this->info("Sent {$sent} abandoned-cart reminder(s).");

        return self::SUCCESS;
    }
}
