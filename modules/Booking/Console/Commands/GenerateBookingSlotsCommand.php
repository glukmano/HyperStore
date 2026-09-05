<?php

declare(strict_types=1);

namespace Modules\Booking\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Booking\Models\BookingResource;
use Modules\Booking\Services\BookingSlotGenerationService;

class GenerateBookingSlotsCommand extends Command
{
    protected $signature = 'bookings:generate-slots {--days=60}';

    protected $description = 'Materializes BookingSlot rows for the next N days from each active Resource\'s AvailabilityRules. Idempotent.';

    public function handle(BookingSlotGenerationService $generationService): int
    {
        $days = (int) $this->option('days');
        $from = CarbonImmutable::now()->startOfDay();
        $to = $from->addDays($days);

        $total = 0;
        BookingResource::where('is_active', true)->get()->each(function (BookingResource $resource) use (&$total, $generationService, $from, $to): void {
            foreach ($resource->eligibleServices as $service) {
                $total += $generationService->generateForServiceResource($service, $resource, $from, $to);
            }
        });

        $this->info("Generated [{$total}] BookingSlot row(s).");

        return self::SUCCESS;
    }
}
