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

    protected $description = 'Expire stale inventory reservations in batches';

    public function handle(InventoryReservationServiceInterface $reservationService): int
    {
        $count = 0;

        InventoryReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<=', Carbon::now())
            ->chunkById(100, function ($batch) use ($reservationService, &$count) {
                foreach ($batch as $res) {
                    if ($reservationService->expire($res)) {
                        $count++;
                    }
                }
            });

        $this->info("Expired [{$count}] stale inventory reservations.");

        return self::SUCCESS;
    }
}
