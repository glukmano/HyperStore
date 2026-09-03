<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property string $display_name
 * @property string|null $logo_url
 * @property string|null $banner_url
 * @property string|null $bio
 * @property array<string, mixed>|null $policies
 * @property array<string, mixed>|null $contact_info
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 */
class VendorStorefrontProfile extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_storefront_profiles';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'display_name',
        'logo_url',
        'banner_url',
        'bio',
        'policies',
        'contact_info',
    ];

    protected $casts = [
        'policies' => 'array',
        'contact_info' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
