<?php

declare(strict_types=1);

namespace Modules\B2B\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\B2B\Enums\CompanyCreditEntryType;
use Modules\B2B\Exceptions\B2BException;

/**
 * Owner Delta §1/§3: pure append-only delta model. No mutable
 * outstanding-balance column anywhere — outstanding exposure is always
 * `SUM(amount_minor)` over every entry for the account. A `release`/
 * `settlement` MUST reference the `reservation` entry it resolves via
 * `reverses_entry_id` (DB-enforced: a reservation may be resolved by AT
 * MOST one release-or-settlement, never both — partial unique index on
 * `reverses_entry_id`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $company_credit_account_id
 * @property CompanyCreditEntryType $entry_type
 * @property int $amount_minor
 * @property string $source_type
 * @property string $source_uuid
 * @property ?int $reverses_entry_id
 * @property CarbonImmutable $created_at
 */
class CompanyCreditEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'company_credit_account_id',
        'entry_type',
        'amount_minor',
        'source_type',
        'source_uuid',
        'reverses_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => CompanyCreditEntryType::class,
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Append-only + immutable — mirrors every other financial entry model
     * platform-wide (VendorPayableEntry, LoyaltyPointEntry, JournalEntry).
     */
    public static function boot(): void
    {
        parent::boot();

        static::updating(function (): void {
            throw new B2BException('CompanyCreditEntry rows are immutable and may never be updated.');
        });

        static::deleting(function (): void {
            throw new B2BException('CompanyCreditEntry rows are immutable and may never be deleted.');
        });
    }

    /**
     * @return BelongsTo<CompanyCreditAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CompanyCreditAccount::class, 'company_credit_account_id');
    }

    /**
     * @return BelongsTo<CompanyCreditEntry, $this>
     */
    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}
