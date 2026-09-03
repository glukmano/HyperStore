<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Ledger\Exceptions\ImmutableFinancialRecordException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $journal_entry_id
 * @property int $ledger_account_id
 * @property string $direction
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $description
 * @property CarbonImmutable $created_at
 * @property-read Tenant $tenant
 * @property-read JournalEntry $journalEntry
 * @property-read LedgerAccount $account
 */
class JournalLine extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'journal_lines';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'journal_entry_id',
        'ledger_account_id',
        'direction',
        'amount_minor',
        'currency',
        'description',
        'created_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'created_at' => 'immutable_datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });

        static::updating(function (): void {
            throw ImmutableFinancialRecordException::forModel(self::class);
        });

        static::deleting(function (): void {
            throw ImmutableFinancialRecordException::forModel(self::class);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
