<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Exceptions\VendorOwnerInvariantViolationException;
use Modules\Marketplace\Services\VendorOwnershipService;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property int $user_id
 * @property VendorRole $role
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 * @property-read User $user
 */
class VendorUser extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_users';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'user_id',
        'role',
        'is_active',
    ];

    protected $casts = [
        'role' => VendorRole::class,
        'is_active' => 'boolean',
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

        static::updating(function (self $model): void {
            if (! VendorOwnershipService::$transferInProgress) {
                $origRole = $model->getOriginal('role');
                $origRoleVal = $origRole instanceof VendorRole ? $origRole->value : (string) $origRole;
                $origActive = (bool) $model->getOriginal('is_active');

                if ($origRoleVal === VendorRole::Owner->value && $origActive === true) {
                    if ($model->role !== VendorRole::Owner || $model->is_active === false) {
                        throw VendorOwnerInvariantViolationException::cannotDemoteOrDeleteOwner();
                    }
                }
            }
        });

        static::deleting(function (self $model): void {
            if (! VendorOwnershipService::$transferInProgress) {
                $origRole = $model->getOriginal('role');
                $origRoleVal = $origRole instanceof VendorRole ? $origRole->value : (string) $origRole;
                $origActive = (bool) $model->getOriginal('is_active');

                if ($origRoleVal === VendorRole::Owner->value && $origActive === true) {
                    throw VendorOwnerInvariantViolationException::cannotDemoteOrDeleteOwner();
                }
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
