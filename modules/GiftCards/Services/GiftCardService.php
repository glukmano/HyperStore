<?php

declare(strict_types=1);

namespace Modules\GiftCards\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GiftCards\Exceptions\GiftCardAlreadyRedeemedException;
use Modules\GiftCards\Exceptions\GiftCardNotFoundException;
use Modules\GiftCards\Models\GiftCard;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Models\StoreValueAccount;
use Modules\Wallet\Services\StoreValueService;

/**
 * Thin — delegates ALL balance/ledger movement to modules/Wallet's
 * StoreValueService, per the plan's explicit design to avoid three
 * duplicated money engines (Owner Delta §15).
 */
final class GiftCardService
{
    public function __construct(
        private readonly StoreValueService $storeValueService
    ) {}

    /**
     * Generates a cryptographically random, high-entropy code — shown to
     * the purchaser ONCE at issuance time, then only its hash is stored.
     *
     * @return array{giftCard: GiftCard, plaintextCode: string}
     */
    public function issue(int $tenantId, string $currency, int $valueMinor, ?string $sourceUuid = null, ?CarbonInterface $expiresAt = null): array
    {
        $plaintextCode = 'GC-'.strtoupper(Str::random(16));
        $codeHash = hash('sha256', $plaintextCode);

        $giftCard = GiftCard::create([
            'tenant_id' => $tenantId,
            'code_hash' => $codeHash,
            'code_last4' => substr($plaintextCode, -4),
            'issuer_scope' => 'tenant',
            'currency' => strtoupper($currency),
            'initial_value_minor' => $valueMinor,
            'status' => 'unactivated',
            'expires_at' => $expiresAt,
        ]);

        // Owner Delta §15: activation creates exactly ONE StoreValueAccount
        // for THIS specific Gift Card — never merged with another card or a
        // customer's Wallet/Store Credit account.
        DB::transaction(function () use ($giftCard, $sourceUuid): void {
            $account = StoreValueAccount::create([
                'tenant_id' => $giftCard->tenant_id,
                'customer_profile_id' => null,
                'instrument_type' => StoreValueInstrumentType::GiftCard->value,
                'currency' => $giftCard->currency,
                'scope' => 'tenant',
                'status' => 'active',
            ]);

            $this->storeValueService->issue(
                $account,
                $giftCard->initial_value_minor,
                'gift_card_activation',
                $sourceUuid ?? "gift_card:{$giftCard->uuid}"
            );

            $giftCard->store_value_account_id = $account->id;
            $giftCard->status = 'active';
            $giftCard->save();
        });

        return ['giftCard' => $giftCard->fresh() ?? $giftCard, 'plaintextCode' => $plaintextCode];
    }

    /**
     * Resolves the StoreValueAccount for a redeemed code. Optionally
     * "claims" it for a Customer (sets customer_profile_id) for storefront
     * convenience — the balance/account itself is unchanged, still scoped
     * to this one Gift Card.
     */
    public function resolveAccountForCode(int $tenantId, string $plaintextCode, ?int $customerProfileId = null): StoreValueAccount
    {
        $codeHash = hash('sha256', $plaintextCode);

        /** @var GiftCard|null $giftCard */
        $giftCard = GiftCard::where('tenant_id', $tenantId)->where('code_hash', $codeHash)->first();
        if ($giftCard === null || $giftCard->store_value_account_id === null) {
            throw GiftCardNotFoundException::forCode();
        }

        if ($giftCard->status === 'redeemed' || $giftCard->status === 'deactivated') {
            throw GiftCardAlreadyRedeemedException::forCard((int) $giftCard->id);
        }

        /** @var StoreValueAccount $account */
        $account = StoreValueAccount::where('id', $giftCard->store_value_account_id)->firstOrFail();

        if ($customerProfileId !== null && $account->customer_profile_id === null) {
            $account->customer_profile_id = $customerProfileId;
            $account->save();
        }

        return $account;
    }

    /**
     * Marks a Gift Card `redeemed` once its balance reaches zero — called
     * after a spend; a no-op if balance remains.
     */
    public function markRedeemedIfExhausted(StoreValueAccount $account): void
    {
        $giftCard = GiftCard::where('store_value_account_id', $account->id)->first();
        if ($giftCard === null || $giftCard->status !== 'active') {
            return;
        }

        $balance = $this->storeValueService->getAvailableBalanceMinor($account);
        if ($balance <= 0) {
            $giftCard->status = 'redeemed';
            $giftCard->save();
        }
    }
}
