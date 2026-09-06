<?php

declare(strict_types=1);

namespace Modules\POS\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\POS\Enums\CashMovementType;

/**
 * Append-only, idempotent by (tenant_id, source_type, source_uuid,
 * movement_type) — mirrors CompanyCreditEntry/StoreValueEntry exactly.
 * Never updated after creation (architecture-tested).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $register_session_id
 * @property CashMovementType $movement_type
 * @property int $amount_minor
 * @property string $currency
 * @property ?string $reason
 * @property string $source_type
 * @property string $source_uuid
 * @property int $created_by_user_id
 */
class PosCashMovement extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'pos_cash_movements';

    protected $fillable = [
        'tenant_id',
        'register_session_id',
        'movement_type',
        'amount_minor',
        'currency',
        'reason',
        'source_type',
        'source_uuid',
        'created_by_user_id',
        'created_at',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (PosCashMovement $movement): void {
            if (empty($movement->uuid)) {
                $movement->uuid = (string) Str::uuid();
            }
            if (empty($movement->created_at)) {
                $movement->created_at = now()->toDateTimeString();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'movement_type' => CashMovementType::class,
            'amount_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosRegisterSession, $this>
     */
    public function registerSession(): BelongsTo
    {
        return $this->belongsTo(PosRegisterSession::class, 'register_session_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
