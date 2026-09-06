<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use Illuminate\Support\Facades\DB;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Customers\Services\CustomerProfileService;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPaymentTenderAllocation;
use Modules\Wallet\Contracts\StoreValueCheckoutHookInterface;
use Modules\Wallet\Enums\StoreValueEntryType;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Modules\Wallet\Exceptions\WalletException;
use Modules\Wallet\Models\StoreValueAccount;
use Modules\Wallet\Models\StoreValueEntry;
use RuntimeException;

/**
 * Owner Delta §16: applying Store Value at Checkout is a hold placed
 * directly (no Coupon-minting proxy, unlike Loyalty's Phase-19 precedent —
 * C.25/D.49). D.49: at most one hold per instrument type present in one
 * checkout, never an arbitrary combination of many accounts of the same
 * type.
 */
class StoreValueCheckoutHook implements StoreValueCheckoutHookInterface
{
    public function __construct(
        private readonly StoreValueService $storeValueService,
        private readonly CustomerProfileService $customerProfileService
    ) {}

    public function applyToCheckout(CheckoutSession $session, string $instrumentType, ?string $accountUuid, int $requestedAmountMinor): array
    {
        $type = StoreValueInstrumentType::from($instrumentType);
        $holdSourceUuid = "{$session->uuid}:{$type->value}";

        $account = $this->resolveAccount($session, $type, $accountUuid);

        return DB::transaction(function () use ($session, $type, $account, $requestedAmountMinor, $holdSourceUuid): array {
            $entry = $this->storeValueService->placeHold($account, $requestedAmountMinor, $holdSourceUuid);
            $appliedMinor = abs($entry->amount_minor);

            $refs = (array) ($session->store_value_hold_refs ?? []);
            $refs[$type->value] = [
                'account_uuid' => $account->uuid,
                'hold_entry_id' => $entry->id,
                'amount_minor' => $appliedMinor,
            ];

            $session->store_value_hold_refs = $refs;
            $session->store_value_applied_minor = array_sum(array_column($refs, 'amount_minor'));
            $session->save();

            return ['applied_minor' => $appliedMinor];
        });
    }

    private function resolveAccount(CheckoutSession $session, StoreValueInstrumentType $type, ?string $accountUuid): StoreValueAccount
    {
        if ($accountUuid !== null) {
            /** @var StoreValueAccount $account */
            $account = StoreValueAccount::where('tenant_id', $session->tenant_id)
                ->where('uuid', $accountUuid)
                ->where('instrument_type', $type->value)
                ->firstOrFail();

            // A Gift Card account may be customer_profile_id=null (guest-
            // spendable) or claimed by THIS session's own customer — never
            // another customer's private Wallet/Store Credit account.
            if ($account->customer_profile_id !== null && $session->user_id !== null) {
                $user = $session->user;
                if ($user === null) {
                    throw new WalletException('Cannot resolve the checkout session\'s customer identity.');
                }
                $customerProfile = $this->customerProfileService->firstOrCreateFor($user);
                if ($account->customer_profile_id !== $customerProfile->id) {
                    throw new WalletException('Store Value account does not belong to this checkout session\'s customer.');
                }
            }

            return $account;
        }

        if ($session->user_id === null) {
            throw new RuntimeException('Cannot resolve a Wallet/Store Credit account for a guest checkout without an explicit accountUuid.');
        }

        $user = $session->user;
        if ($user === null) {
            throw new RuntimeException('Cannot resolve the checkout session\'s customer identity.');
        }
        $customerProfile = $this->customerProfileService->firstOrCreateFor($user);

        return $this->storeValueService->findOrCreateAccount(
            $session->tenant_id,
            $customerProfile->id,
            $type,
            $session->currency
        );
    }

    public function removeFromCheckout(CheckoutSession $session): void
    {
        $this->releaseHoldsForCheckout($session);
    }

    public function releaseHoldsForCheckout(CheckoutSession $session): void
    {
        $refs = (array) ($session->store_value_hold_refs ?? []);
        if ($refs === []) {
            return;
        }

        DB::transaction(function () use ($session, $refs): void {
            foreach ($refs as $ref) {
                /** @var StoreValueEntry|null $holdEntry */
                $holdEntry = StoreValueEntry::find($ref['hold_entry_id']);
                if ($holdEntry === null || $holdEntry->entry_type !== StoreValueEntryType::Hold) {
                    continue;
                }

                $this->storeValueService->releaseHold($holdEntry, $holdEntry->source_uuid);
            }

            $session->store_value_hold_refs = null;
            $session->store_value_applied_minor = 0;
            $session->save();
        });
    }

    public function convertHoldsToCaptureForOrder(Order $order, int $gatewayCapturedAmountMinor, string $gatewayTransactionUuid): void
    {
        /** @var CheckoutSession|null $session */
        $session = CheckoutSession::where('id', $order->checkout_id)->first();
        if ($session === null) {
            return;
        }

        $refs = (array) ($session->store_value_hold_refs ?? []);

        DB::transaction(function () use ($order, $refs, $gatewayCapturedAmountMinor, $gatewayTransactionUuid): void {
            foreach ($refs as $ref) {
                /** @var StoreValueEntry|null $holdEntry */
                $holdEntry = StoreValueEntry::find($ref['hold_entry_id']);
                if ($holdEntry === null || $holdEntry->entry_type !== StoreValueEntryType::Hold) {
                    continue;
                }

                $captureEntry = $this->storeValueService->captureHold($holdEntry, 'order', (string) $order->uuid);

                OrderPaymentTenderAllocation::firstOrCreate([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'tender_type' => $holdEntry->instrument_type->value,
                    'source_reference' => (string) $captureEntry->id,
                ], [
                    'amount_minor' => abs($holdEntry->amount_minor),
                    'currency' => $holdEntry->currency,
                ]);
            }

            if ($gatewayCapturedAmountMinor > 0) {
                OrderPaymentTenderAllocation::firstOrCreate([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'tender_type' => 'external_gateway',
                    'source_reference' => $gatewayTransactionUuid,
                ], [
                    'amount_minor' => $gatewayCapturedAmountMinor,
                    'currency' => $order->currency,
                ]);
            }
        });
    }
}
