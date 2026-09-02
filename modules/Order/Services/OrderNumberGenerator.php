<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\Order\Contracts\OrderNumberGeneratorInterface;
use Modules\Order\Models\OrderNumberCounter;

class OrderNumberGenerator implements OrderNumberGeneratorInterface
{
    public function generate(int $tenantId, DateTimeZone $businessTimezone): string
    {
        $businessDate = Carbon::now('UTC')->setTimezone($businessTimezone)->format('Ymd');

        if (DB::getDriverName() === 'pgsql') {
            /** @var object{last_value: int|string}|null $row */
            $row = DB::selectOne('
                INSERT INTO order_number_counters (tenant_id, business_date, last_value, created_at, updated_at)
                VALUES (?, ?, 1, NOW(), NOW())
                ON CONFLICT (tenant_id, business_date)
                DO UPDATE SET last_value = order_number_counters.last_value + 1, updated_at = NOW()
                RETURNING last_value
            ', [$tenantId, $businessDate]);

            $counter = (int) ($row->last_value ?? 1);
        } else {
            $counter = DB::transaction(function () use ($tenantId, $businessDate): int {
                /** @var OrderNumberCounter|null $record */
                $record = OrderNumberCounter::query()
                    ->where('tenant_id', $tenantId)
                    ->where('business_date', $businessDate)
                    ->lockForUpdate()
                    ->first();

                if ($record === null) {
                    OrderNumberCounter::create([
                        'tenant_id' => $tenantId,
                        'business_date' => $businessDate,
                        'last_value' => 1,
                    ]);

                    return 1;
                }

                $record->last_value += 1;
                $record->save();

                return (int) $record->last_value;
            });
        }

        return sprintf('ORD-%s-%06d', $businessDate, $counter);
    }
}
