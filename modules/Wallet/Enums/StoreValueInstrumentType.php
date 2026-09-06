<?php

declare(strict_types=1);

namespace Modules\Wallet\Enums;

/**
 * Owner Delta §15: three distinct instrument types from the base model
 * onward — a redeemed Gift Card is never converted into Wallet/Store
 * Credit merely because a code was entered once.
 */
enum StoreValueInstrumentType: string
{
    case Wallet = 'wallet';
    case StoreCredit = 'store_credit';
    case GiftCard = 'gift_card';
}
