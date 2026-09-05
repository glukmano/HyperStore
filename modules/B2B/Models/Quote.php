<?php

declare(strict_types=1);

namespace Modules\B2B\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\B2B\Enums\QuoteStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $company_id
 * @property QuoteStatus $status
 * @property ?CarbonImmutable $valid_until
 * @property string $currency
 * @property int $created_by_user_id
 * @property ?int $quoted_by_user_id
 * @property-read Collection<int, QuoteLine> $lines
 */
class Quote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'company_id',
        'status',
        'valid_until',
        'currency',
        'created_by_user_id',
        'quoted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'valid_until' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Quote $quote): void {
            $quote->uuid ??= (string) Str::uuid();
        });
    }

    public function isAcceptable(): bool
    {
        return $this->status === QuoteStatus::Quoted
            && ($this->valid_until === null || ! $this->valid_until->isPast());
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<QuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }
}
