<?php

declare(strict_types=1);

namespace Modules\POS\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\POS\Enums\RegisterSessionStatus;

/**
 * Owner Delta §1/§3: exactly one active session per register is DB-enforced
 * (partial unique index, see migration). opening_cash_minor is an immutable
 * snapshot written in the same transaction as the authoritative
 * opening_float PosCashMovement row — never independently editable after
 * that. closing_cash_counted_minor/closing_cash_expected_minor/
 * closing_variance_minor are the closing SNAPSHOT only; expected cash is
 * always derived live from real cash movements, never cached elsewhere.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $register_id
 * @property int $cashier_user_id
 * @property RegisterSessionStatus $status
 * @property string $currency
 * @property int $opening_cash_minor
 * @property ?int $closing_cash_counted_minor
 * @property ?int $closing_cash_expected_minor
 * @property ?int $closing_variance_minor
 */
class PosRegisterSession extends Model
{
    use BelongsToTenant;

    protected $table = 'pos_register_sessions';

    protected $fillable = [
        'tenant_id',
        'register_id',
        'cashier_user_id',
        'status',
        'currency',
        'opening_cash_minor',
        'closing_cash_counted_minor',
        'closing_cash_expected_minor',
        'closing_variance_minor',
        'opened_at',
        'closed_at',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (PosRegisterSession $session): void {
            if (empty($session->uuid)) {
                $session->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => RegisterSessionStatus::class,
            'opening_cash_minor' => 'integer',
            'closing_cash_counted_minor' => 'integer',
            'closing_cash_expected_minor' => 'integer',
            'closing_variance_minor' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PosRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'register_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    /**
     * @return HasMany<PosCashMovement, $this>
     */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'register_session_id');
    }

    public function isActive(): bool
    {
        return $this->status === RegisterSessionStatus::ACTIVE;
    }
}
