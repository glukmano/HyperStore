<?php

declare(strict_types=1);

namespace Modules\GiftCards\Models;

use App\Core\Tenancy\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Wallet\Models\StoreValueAccount;

/**
 * Owner Delta §15: the plaintext code is NEVER persisted — only its hash
 * (lookup) and last 4 characters (operator-safe display) are stored. A
 * GiftCard and its StoreValueAccount are a permanent one-to-one pair,
 * never merged with another Gift Card or a Customer's Wallet/Store
 * Credit account.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $code_hash
 * @property string $code_last4
 * @property string $issuer_scope
 * @property string $currency
 * @property int $initial_value_minor
 * @property string $status
 * @property ?int $store_value_account_id
 * @property ?CarbonInterface $expires_at
 */
class GiftCard extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'code_hash',
        'code_last4',
        'issuer_scope',
        'currency',
        'initial_value_minor',
        'status',
        'store_value_account_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'initial_value_minor' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (GiftCard $card): void {
            if (empty($card->uuid)) {
                $card->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<StoreValueAccount, $this>
     */
    public function storeValueAccount(): BelongsTo
    {
        return $this->belongsTo(StoreValueAccount::class, 'store_value_account_id');
    }
}
