<?php

declare(strict_types=1);

namespace Modules\B2B\Exceptions;

final class QuoteNotAcceptableException extends B2BException
{
    public static function forQuote(int $quoteId, string $reason): self
    {
        return new self("Quote [{$quoteId}] cannot be accepted: {$reason}.");
    }
}
