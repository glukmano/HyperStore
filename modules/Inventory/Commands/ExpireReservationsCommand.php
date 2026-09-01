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
        $now = Carbon::now();

        // Fetch candidates: active reservations without an adoption owner (owner_type IS NULL)
        // with an expires_at that has already passed.
        // Adopted reservations (owner_type IS NOT NULL) are EXCLUDED by the Inventory-domain
        // predicate `isEligibleForAutomaticExpiration` and never released here.
        InventoryReservation::query()
            ->where('status', 'active')
            ->whereNull('owner_type')
            ->where('expires_at', '<=', $now)
            ->chunkById(100, function ($batch) use ($reservationService, &$count, $now) {
                foreach ($batch as $res) {
                    /** @var InventoryReservation $res */
                    if (! $res->isEligibleForAutomaticExpiration($now)) {
                        // Double-check domain predicate after chunk load
                        continue;
                    }

                    if ($reservationService->expire($res)) {
                        $count++;
                    }
                }
            });

        $this->info("Expired [{$count}] stale inventory reservations.");

        return self::SUCCESS;
    }
}
