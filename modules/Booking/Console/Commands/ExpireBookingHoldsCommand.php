<?php

declare(strict_types=1);

namespace Modules\Booking\Console\Commands;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Modules\Booking\Services\BookingHoldService;

class ExpireBookingHoldsCommand extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Cancels held Bookings whose hold has expired without a completed Checkout.';

    public function handle(BookingHoldService $holdService): int
    {
        $total = 0;
        foreach (Tenant::pluck('id') as $tenantId) {
            $total += $holdService->expireHeldPastDeadline((int) $tenantId);
        }

        $this->info("Expired [{$total}] Booking hold(s).");

        return self::SUCCESS;
    }
}
