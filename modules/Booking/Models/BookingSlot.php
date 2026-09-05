<?php

declare(strict_types=1);

namespace Modules\Booking\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Owner Delta §8: `unique(booking_resource_id, starts_at)` makes repeated
 * generation-command runs idempotent. Owner Delta §6/§8: no cached
 * confirmed_count/held_count column — used capacity is always a live
 * `COUNT(*)` over `bookings` rows for this slot, computed inside the row
 * lock.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_resource_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $capacity
 */
class BookingSlot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'booking_resource_id',
        'booking_service_id',
        'starts_at',
        'ends_at',
        'capacity',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BookingResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id');
    }

    /**
     * @return BelongsTo<BookingService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(BookingService::class, 'booking_service_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function usedCapacity(): int
    {
        return $this->bookings()->whereIn('status', ['held', 'confirmed'])->count();
    }
}
