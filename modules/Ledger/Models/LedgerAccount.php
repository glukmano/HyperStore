<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Ledger\Exceptions\AccountInUseException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $normal_balance
 * @property string|null $role
 * @property string|null $currency
 * @property bool $is_system
 * @property string $status
 * @property string|null $description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, JournalLine> $lines
 */
class LedgerAccount extends Model
{
    use BelongsToTenant;

    protected $table = 'ledger_accounts';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'code',
        'name',
        'type',
        'normal_balance',
        'role',
        'currency',
        'is_system',
        'status',
        'description',
    ];

    protected $casts = [
        'is_system' => 'boolean',
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

        static::deleting(function (self $model): void {
            if ($model->lines()->exists()) {
                throw AccountInUseException::cannotDelete((int) $model->id);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'ledger_account_id');
    }
}
