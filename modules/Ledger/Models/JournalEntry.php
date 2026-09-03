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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\Ledger\Exceptions\ImmutableFinancialRecordException;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $source_module
 * @property string $source_type
 * @property string $source_uuid
 * @property string $posting_type
 * @property string $currency
 * @property int|null $reverses_journal_entry_id
 * @property string $description
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $effective_at
 * @property CarbonImmutable $posted_at
 * @property CarbonImmutable $created_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, JournalLine> $lines
 * @property-read JournalEntry|null $reversedEntry
 * @property-read JournalEntry|null $reversalEntry
 */
class JournalEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'journal_entries';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'source_module',
        'source_type',
        'source_uuid',
        'posting_type',
        'currency',
        'reverses_journal_entry_id',
        'description',
        'metadata',
        'effective_at',
        'posted_at',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'effective_at' => 'immutable_datetime',
        'posted_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if ($model->created_at === null) {
                $model->created_at = CarbonImmutable::now('UTC');
            }
        });

        static::updating(function (): void {
            throw ImmutableFinancialRecordException::forEntity('JournalEntry');
        });

        static::deleting(function (): void {
            throw ImmutableFinancialRecordException::forEntity('JournalEntry');
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
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_entry_id');
    }

    /**
     * @return HasOne<JournalEntry, $this>
     */
    public function reversalEntry(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_journal_entry_id');
    }

    public function isReversed(): bool
    {
        return self::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant_id)
            ->where('reverses_journal_entry_id', $this->id)
            ->exists();
    }
}
