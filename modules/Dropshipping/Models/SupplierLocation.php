<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $supplier_id
 * @property string $code
 * @property string $name
 * @property string $country_code
 * @property string|null $state_province
 * @property string $city
 * @property string $postal_code
 * @property string $address_line1
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Supplier $supplier
 * @property-read Collection<int, SupplierOffer> $offers
 */
class SupplierLocation extends Model
{
    protected $table = 'supplier_locations';

    protected $fillable = [
        'uuid',
        'supplier_id',
        'code',
        'name',
        'country_code',
        'state_province',
        'city',
        'postal_code',
        'address_line1',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

        });
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    /**
     * @return HasMany<SupplierOffer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class, 'supplier_location_id');
    }

    public function getLocationCodeAttribute(): string
    {
        return (string) $this->code;
    }
}
