<?php

declare(strict_types=1);

namespace Modules\Booking\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner Delta §16 (carried from the coordinated plan): recurring
 * availability is expressed in the resource's OWN IANA timezone — DST is
 * handled by Carbon's timezone-aware arithmetic at slot-generation time,
 * never a raw stored UTC offset.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_resource_id
 * @property int $weekday
 * @property string $start_time
 * @property string $end_time
 * @property string $timezone
 */
class AvailabilityRule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'booking_resource_id',
        'weekday',
        'start_time',
        'end_time',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BookingResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id');
    }
}
