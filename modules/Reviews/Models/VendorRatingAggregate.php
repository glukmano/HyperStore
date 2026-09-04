<?php

declare(strict_types=1);

namespace Modules\Reviews\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Marketplace\Models\Vendor;

/**
 * @property int $vendor_id
 * @property int $tenant_id
 * @property float $average_rating
 * @property int $review_count
 */
class VendorRatingAggregate extends Model
{
    protected $primaryKey = 'vendor_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['vendor_id', 'tenant_id', 'average_rating', 'review_count', 'updated_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vendor_id' => 'integer',
            'tenant_id' => 'integer',
            'average_rating' => 'decimal:2',
            'review_count' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
