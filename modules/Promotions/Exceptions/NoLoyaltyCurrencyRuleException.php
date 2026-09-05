<?php

declare(strict_types=1);

namespace Modules\Promotions\Exceptions;

/**
 * Owner Delta correction §10: no implicit currency conversion — a currency
 * with no configured rule simply cannot earn or redeem points.
 */
final class NoLoyaltyCurrencyRuleException extends \RuntimeException
{
    public static function forCurrency(string $currency): self
    {
        return new self("No active Loyalty currency rule configured for '{$currency}'.");
    }
}
