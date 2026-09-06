<?php

declare(strict_types=1);

namespace Modules\POS\Services;

use App\Core\Stores\Models\StoreUser;
use Modules\POS\Models\PosRegister;

/**
 * Reuses the existing store_users pivot (Store <-> User scoping) exactly as
 * every other module already does — no new POS-specific staff table.
 */
final class PosCashierAuthorizationService
{
    public function isAuthorizedForRegister(int $userId, PosRegister $register): bool
    {
        $storeMarket = $register->storeMarket;
        if ($storeMarket === null) {
            return false;
        }

        $storeId = $storeMarket->store_id;

        return StoreUser::query()
            ->where('store_id', $storeId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->exists();
    }
}
