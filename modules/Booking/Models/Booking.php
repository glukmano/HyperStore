<?php

declare(strict_types=1);

namespace Modules\Booking\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Booking\Enums\BookingStatus;
use Modules\Customers\Models\CustomerProfile;

/**
 * Owner Delta §5: `unique(booking_slot_id, checkout_session_uuid)` is
 * UNCONDITIONAL (no status filter) — the SAME row survives held -> confirmed
 * -> completed/cancelled, so a retried hold-then-confirm sequence from the
 * same Checkout session never creates a second Booking regardless of the
 * row's current status.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $customer_profile_id
 * @property int $booking_service_id
 * @property int $booking_slot_id
 * @property BookingStatus $status
 * @property ?CarbonImmutable $hold_expires_at
 * @property string $checkout_session_uuid
 * @property ?int $order_id
 */
class Booking extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'customer_profile_id',
        'booking_service_id',
        'booking_slot_id',
        'status',
        'hold_expires_at',
        'checkout_session_uuid',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'hold_expires_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            $booking->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<BookingSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(BookingSlot::class, 'booking_slot_id');
    }

    /**
     * @return BelongsTo<BookingService, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(BookingService::class, 'booking_service_id');
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }
}
