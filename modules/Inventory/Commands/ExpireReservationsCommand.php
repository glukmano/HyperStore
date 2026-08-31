<?php

declare(strict_types=1);

namespace Modules\Inventory\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Models\InventoryReservation;

class ExpireReservationsCommand extends Command
{
    protected $signature = 'inventory:expire-reservations';

    protected $description = 'Expire stale inventory reservations that have passed their expiration timestamp';

    public function handle(InventoryReservationServiceInterface $reservationService): int
    {
        $stale = InventoryReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', Carbon::now())
            ->get();

        $count = 0;
        foreach ($stale as $res) {
            if ($reservationService->expire($res)) {
                $count++;
            }
        }

        $this->info("Expired [{$count}] stale inventory reservations.");

        return self::SUCCESS;
    }
}
