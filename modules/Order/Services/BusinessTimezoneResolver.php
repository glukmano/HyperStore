<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use DateTimeZone;
use Modules\Order\Contracts\BusinessTimezoneResolverInterface;
use Modules\Order\Exceptions\InvalidBusinessTimezoneException;
use Throwable;

class BusinessTimezoneResolver implements BusinessTimezoneResolverInterface
{
    public function resolve(int $marketId, int $storeId): DateTimeZone
    {
        /** @var Market|null $market */
        $market = Market::find($marketId);

        /** @var Store|null $store */
        $store = Store::find($storeId);

        $tzCandidate = null;

        // Precedence 1: Market timezone
        if ($market !== null && ! empty($market->timezone)) {
            $tzCandidate = (string) $market->timezone;
        }

        // Precedence 2: Store configured timezone
        if ($tzCandidate === null && $store !== null && ! empty($store->settings['timezone'])) {
            $tzCandidate = (string) $store->settings['timezone'];
        }

        if ($tzCandidate === null) {
            throw InvalidBusinessTimezoneException::unresolvable($marketId, $storeId);
        }

        if (! in_array($tzCandidate, timezone_identifiers_list(), true)) {
            throw InvalidBusinessTimezoneException::invalid($tzCandidate);
        }

        try {
            return new DateTimeZone($tzCandidate);
        } catch (Throwable) {
            throw InvalidBusinessTimezoneException::invalid($tzCandidate);
        }
    }
}
