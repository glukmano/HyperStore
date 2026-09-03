<?php

declare(strict_types=1);

namespace Modules\Marketplace\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Marketplace\Enums\VendorInvitationStatus;
use Modules\Marketplace\Enums\VendorRole;
use Modules\Marketplace\Exceptions\VendorInvitationException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $vendor_id
 * @property string $email
 * @property VendorRole $role
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property VendorInvitationStatus $status
 * @property int|null $accepted_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Vendor $vendor
 * @property-read User|null $acceptedByUser
 */
class VendorInvitation extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_invitations';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'vendor_id',
        'email',
        'role',
        'token_hash',
        'expires_at',
        'status',
        'accepted_by_user_id',
    ];

    protected $casts = [
        'role' => VendorRole::class,
        'status' => VendorInvitationStatus::class,
        'expires_at' => 'immutable_datetime',
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

            if ($model->role === VendorRole::Owner) {
                throw VendorInvitationException::ownerRoleForbidden();
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
    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }
}
