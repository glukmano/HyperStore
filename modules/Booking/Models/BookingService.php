<?php

declare(strict_types=1);

namespace Modules\Booking\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Catalog\Models\Product;

/**
 * Owner Delta §4: the smallest Booking-owned configuration model — Catalog
 * Product stays the sellable identity; duration/timezone/buffer live here,
 * never scattered onto Product.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $duration_minutes
 * @property string $timezone
 * @property int $buffer_minutes
 */
class BookingService extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'duration_minutes',
        'timezone',
        'buffer_minutes',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Owner Delta §4: a Customer's slot selection is provably
     * Product -> Service -> ELIGIBLE Resource -> generated Slot — never an
     * arbitrary Tenant-wide Resource.
     *
     * @return BelongsToMany<BookingResource, $this>
     */
    public function eligibleResources(): BelongsToMany
    {
        return $this->belongsToMany(BookingResource::class, 'booking_service_resources');
    }
}
