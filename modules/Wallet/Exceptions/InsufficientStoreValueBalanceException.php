<?php

declare(strict_types=1);

namespace Modules\Wallet\Exceptions;

class InsufficientStoreValueBalanceException extends WalletException
{
    public static function forAccount(int $accountId, int $requestedMinor, int $availableMinor): self
    {
        return new self("Store Value account [{$accountId}] has insufficient balance: requested [{$requestedMinor}], available [{$availableMinor}].");
    }
}
