<?php

declare(strict_types=1);

namespace Modules\GiftCards\Exceptions;

class GiftCardAlreadyRedeemedException extends GiftCardException
{
    public static function forCard(int $giftCardId): self
    {
        return new self("Gift Card [{$giftCardId}] has already been fully redeemed.");
    }
}
