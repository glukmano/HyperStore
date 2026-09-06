<?php

declare(strict_types=1);

namespace Modules\Wallet\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Wallet\Enums\StoreValueEntryType;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Exceptions\WalletException;

/**
 * Owner Delta §16: append-only. `amount_minor` is always a signed delta to
 * the account's SPENDABLE balance: issue/release/refund_credit/
 * manual_adjustment_credit = positive, hold/expire/manual_adjustment_debit
 * = negative, capture = ZERO (the balance was already reduced by the hold
 * it resolves — capture only marks that hold final via `reverses_entry_id`
 * and is the point that posts to Ledger). No mutable balance column
 * anywhere — available balance is always `SUM(amount_minor)`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $store_value_account_id
 * @property StoreValueInstrumentType $instrument_type
 * @property StoreValueEntryType $entry_type
 * @property int $amount_minor
 * @property string $currency
 * @property string $source_type
 * @property string $source_uuid
 * @property ?int $reverses_entry_id
 * @property CarbonImmutable $created_at
 */
class StoreValueEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'store_value_account_id',
        'instrument_type',
        'entry_type',
        'amount_minor',
        'currency',
        'source_type',
        'source_uuid',
        'reverses_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'instrument_type' => StoreValueInstrumentType::class,
            'entry_type' => StoreValueEntryType::class,
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function boot(): void
    {
        parent::boot();

        static::updating(function (): void {
            throw new WalletException('StoreValueEntry rows are immutable and may never be updated.');
        });

        static::deleting(function (): void {
            throw new WalletException('StoreValueEntry rows are immutable and may never be deleted.');
        });
    }

    /**
     * @return BelongsTo<StoreValueAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(StoreValueAccount::class, 'store_value_account_id');
    }

    /**
     * @return BelongsTo<StoreValueEntry, $this>
     */
    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }
}
