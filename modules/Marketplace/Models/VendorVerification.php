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
 * @property string $provider_name
 * @property string|null $external_reference_id
 * @property string $status
 * @property string|null $rejection_reason_code
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 */
class VendorVerification extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_verifications';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'provider_name',
        'external_reference_id',
        'status',
        'rejection_reason_code',
        'metadata',
        'submitted_at',
        'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'submitted_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
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
