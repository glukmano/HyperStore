<?php

declare(strict_types=1);

namespace Modules\Booking\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_resource_id
 * @property string $date
 * @property string $type
 * @property ?string $start_time
 * @property ?string $end_time
 */
class AvailabilityException extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'booking_resource_id',
        'date',
        'type',
        'start_time',
        'end_time',
    ];

    /**
     * @return BelongsTo<BookingResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id');
    }
}
