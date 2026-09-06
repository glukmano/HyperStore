<?php

declare(strict_types=1);

namespace Modules\GiftCards\Exceptions;

class GiftCardNotFoundException extends GiftCardException
{
    public static function forCode(): self
    {
        return new self('No Gift Card matches the supplied code.');
    }
}
