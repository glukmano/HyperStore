<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\ReservationOwnerType;
use Modules\Inventory\ValueObjects\Quantity;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $reservation_key
 * @property string $status active|committed|released|expired
 * @property Carbon|null $expires_at null = indefinitely retained (adopted)
 * @property Carbon|null $released_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $adopted_at
 * @property string|null $owner_type Inventory-owned enum value (ReservationOwnerType)
 * @property string|null $owner_reference Opaque owner reference (e.g. Order UUID)
 * @property array<string,mixed>|null $metadata
 */
class InventoryReservation extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_reservations';

    protected $fillable = [
        'tenant_id',
        'reservation_key',
        'status',
        'expires_at',
        'released_at',
        'committed_at',
        'adopted_at',
        'owner_type',
        'owner_reference',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'committed_at' => 'datetime',
        'adopted_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * @return HasMany<InventoryReservationAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(InventoryReservationAllocation::class);
    }

    public function getTotalQuantity(): Quantity
    {
        $total = Quantity::zero();
        foreach ($this->allocations as $alloc) {
            /** @var InventoryReservationAllocation $alloc */
            $total = $total->add(Quantity::fromString((string) $alloc->quantity));
        }

        return $total;
    }

    /**
     * Inventory-domain predicate: is this reservation eligible for automatic expiration?
     *
     * A reservation is NEVER eligible for automatic expiration if it has been adopted
     * (i.e. owner_type IS NOT NULL). Only unadopted (Checkout-only) active reservations
     * with a passed expires_at are eligible.
     */
    public function isEligibleForAutomaticExpiration(Carbon $now): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Adopted reservations have null expires_at and are explicitly retained.
        if ($this->owner_type !== null) {
            return false;
        }

        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lte($now);
    }

    /**
     * Returns true if this reservation is adopted by the given owner.
     */
    public function isAdoptedBy(ReservationOwnerType $ownerType, string $ownerReference): bool
    {
        return $this->owner_type === $ownerType->value
            && $this->owner_reference === $ownerReference;
    }

    /**
     * Returns true if this reservation has any adoption owner set.
     */
    public function isAdopted(): bool
    {
        return $this->owner_type !== null;
    }
}
