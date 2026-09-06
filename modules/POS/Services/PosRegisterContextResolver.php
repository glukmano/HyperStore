<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use App\Core\Markets\Models\MarketCurrency;
use App\Core\Markets\Models\StoreMarket;
use Modules\POS\DTOs\PosRegisterContext;
use Modules\POS\Exceptions\PosRegisterContextException;
use Modules\POS\Models\PosRegister;

/**
 * Owner Delta §1: proves Tenant -> Store -> active StoreMarket -> Market ->
 * allowed Currency -> POS Channel -> InventorySource in one resolution.
 * No implicit Market selection anywhere — a Register whose StoreMarket is
 * not (or is no longer) active is rejected outright.
 */
final class PosRegisterContextResolver
{
    public function resolve(PosRegister $register): PosRegisterContext
    {
        /** @var StoreMarket|null $storeMarket */
        $storeMarket = StoreMarket::query()
            ->where('id', $register->store_market_id)
            ->where('is_active', true)
            ->first();

        if ($storeMarket === null) {
            throw new PosRegisterContextException(
                "Register [{$register->id}] StoreMarket [{$register->store_market_id}] is missing or not active."
            );
        }

        $store = $storeMarket->store;
        if ($store === null) {
            throw new PosRegisterContextException("StoreMarket [{$storeMarket->id}] has no resolvable Store.");
        }

        if ((int) $register->tenant_id !== (int) $store->tenant_id) {
            throw new PosRegisterContextException(
                "Register [{$register->id}] Tenant [{$register->tenant_id}] does not match StoreMarket's Store Tenant."
            );
        }

        /** @var list<string> $allowedCurrencies */
        $allowedCurrencies = array_values(array_map(
            static fn (mixed $code): string => (string) $code,
            MarketCurrency::where('market_id', $storeMarket->market_id)->pluck('currency_code')->all()
        ));

        if (empty($allowedCurrencies)) {
            throw new PosRegisterContextException(
                "Market [{$storeMarket->market_id}] has no configured allowed currencies."
            );
        }

        if ((int) $register->inventory_source_id === 0 || $register->inventorySource === null) {
            throw new PosRegisterContextException(
                "Register [{$register->id}] has no valid InventorySource."
            );
        }

        return new PosRegisterContext(
            tenantId: (int) $register->tenant_id,
            storeId: (int) $storeMarket->store_id,
            marketId: (int) $storeMarket->market_id,
            channelId: (int) $register->channel_id,
            inventorySourceId: (int) $register->inventory_source_id,
            allowedCurrencies: $allowedCurrencies,
        );
    }

    public function assertCurrencyAllowed(PosRegisterContext $context, string $currency): void
    {
        if (! in_array($currency, $context->allowedCurrencies, true)) {
            throw new PosRegisterContextException(
                "Currency [{$currency}] is not allowed for Market [{$context->marketId}]."
            );
        }
    }
}
