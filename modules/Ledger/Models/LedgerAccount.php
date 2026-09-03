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
use Modules\Ledger\Enums\AccountStatus;
use Modules\Ledger\Enums\AccountType;
use Modules\Ledger\Enums\NormalBalance;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Exceptions\AccountInUseException;
use Modules\Ledger\Exceptions\LedgerAccountInvariantException;

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

            if ($model->role === SystemAccountRole::PAYMENT_CLEARING->value) {
                if (! $model->is_system) {
                    throw LedgerAccountInvariantException::requiredSystemAccountMustBeSystem($model->role);
                }
                if ($model->type !== AccountType::ASSET->value) {
                    throw LedgerAccountInvariantException::invalidClassification($model->role, AccountType::ASSET->value, $model->type);
                }
                if ($model->normal_balance !== NormalBalance::DEBIT->value) {
                    throw LedgerAccountInvariantException::invalidClassification($model->role, NormalBalance::DEBIT->value, $model->normal_balance);
                }
            }

            if ($model->role === SystemAccountRole::CUSTOMER_FUNDS_LIABILITY->value) {
                if (! $model->is_system) {
                    throw LedgerAccountInvariantException::requiredSystemAccountMustBeSystem($model->role);
                }
                if ($model->type !== AccountType::LIABILITY->value) {
                    throw LedgerAccountInvariantException::invalidClassification($model->role, AccountType::LIABILITY->value, $model->type);
                }
                if ($model->normal_balance !== NormalBalance::CREDIT->value) {
                    throw LedgerAccountInvariantException::invalidClassification($model->role, NormalBalance::CREDIT->value, $model->normal_balance);
                }
            }
        });

        static::updating(function (self $model): void {
            $originalIsSystem = (bool) $model->getOriginal('is_system');
            $originalRole = $model->getOriginal('role');

            // 1. For system-managed accounts, prevent unauthorized mutation of classification
            if ($originalIsSystem || $originalRole !== null) {
                if ($model->isDirty('tenant_id')) {
                    throw LedgerAccountInvariantException::cannotMutateSystemField('tenant_id');
                }
                if ($model->isDirty('role')) {
                    throw LedgerAccountInvariantException::cannotMutateSystemField('role');
                }
                if ($model->isDirty('type')) {
                    throw LedgerAccountInvariantException::cannotMutateSystemField('type');
                }
                if ($model->isDirty('normal_balance')) {
                    throw LedgerAccountInvariantException::cannotMutateSystemField('normal_balance');
                }
                if ($model->isDirty('is_system')) {
                    throw LedgerAccountInvariantException::cannotMutateSystemField('is_system');
                }

                // Prevent archiving/deactivating required Phase-10 system accounts
                if (in_array($originalRole, [SystemAccountRole::PAYMENT_CLEARING->value, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY->value], true)) {
                    if ($model->isDirty('status') && $model->status !== AccountStatus::ACTIVE->value) {
                        throw LedgerAccountInvariantException::cannotArchiveRequiredSystemAccount((string) $originalRole);
                    }
                }
            }

            // 2. Once ANY journal line references an account, prevent accounting-meaning mutation
            if ($model->lines()->exists()) {
                if ($model->isDirty('tenant_id')) {
                    throw LedgerAccountInvariantException::cannotMutatePostedField('tenant_id');
                }
                if ($model->isDirty('role')) {
                    throw LedgerAccountInvariantException::cannotMutatePostedField('role');
                }
                if ($model->isDirty('type')) {
                    throw LedgerAccountInvariantException::cannotMutatePostedField('type');
                }
                if ($model->isDirty('normal_balance')) {
                    throw LedgerAccountInvariantException::cannotMutatePostedField('normal_balance');
                }
                if ($model->isDirty('currency')) {
                    throw LedgerAccountInvariantException::cannotMutatePostedField('currency');
                }
            }
        });

        static::deleting(function (self $model): void {
            // Required system accounts must never be ordinary-deletable even with 0 lines
            if (in_array($model->role, [SystemAccountRole::PAYMENT_CLEARING->value, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY->value], true)
                || ($model->is_system && $model->role !== null)
            ) {
                throw LedgerAccountInvariantException::cannotDeleteSystemAccount((string) $model->role);
            }

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
