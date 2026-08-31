<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\ValueObjects\Quantity;

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
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'committed_at' => 'datetime',
        'metadata' => 'array',
    ];

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
}
