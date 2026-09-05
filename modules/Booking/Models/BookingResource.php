<?php

declare(strict_types=1);

namespace Modules\Booking\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int $capacity
 * @property bool $is_active
 */
class BookingResource extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<BookingService, $this>
     */
    public function eligibleServices(): BelongsToMany
    {
        return $this->belongsToMany(BookingService::class, 'booking_service_resources');
    }

    /**
     * @return HasMany<AvailabilityRule, $this>
     */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    /**
     * @return HasMany<AvailabilityException, $this>
     */
    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }

    /**
     * @return HasMany<BookingSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(BookingSlot::class);
    }
}
