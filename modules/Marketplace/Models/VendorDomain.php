<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Routing\HostnameClaimService;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Marketplace\Enums\VendorDomainStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property string $domain
 * @property string $verification_token
 * @property VendorDomainStatus $status
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 */
class VendorDomain extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_domains';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'domain',
        'verification_token',
        'status',
        'verified_at',
    ];

    protected $casts = [
        'status' => VendorDomainStatus::class,
        'verified_at' => 'immutable_datetime',
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

            // Phase-18 Owner Delta §5: same global hostname-claim registry
            // Store/Market domains use — prevents this domain from also
            // being claimed as a Store or Market hostname.
            app(HostnameClaimService::class)->claim($model->domain, 'vendor', (int) $model->vendor_id);
        });

        static::deleted(function (self $model): void {
            app(HostnameClaimService::class)->release($model->domain);
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
