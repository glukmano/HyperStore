<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use InvalidArgumentException;

/**
 * Fail-closed boundary check for quantities crossing into Inventory's scale-4
 * (NUMERIC(14,4)) domain from a wider-scale source (e.g. Order/Dropshipping's
 * NUMERIC(20,8) columns). Never silently rounds — ADR-0129.
 */
final class QuantityScaleGuard
{
    private const int INVENTORY_SCALE = 4;

    public static function assertScale4Representable(string $value, string $context = 'quantity'): void
    {
        $trimmed = trim($value);
        if (! is_numeric($trimmed)) {
            throw new InvalidArgumentException("Invalid {$context} value [{$value}]: not numeric.");
        }

        $sign = str_starts_with($trimmed, '-') ? '-' : '';
        $unsigned = ltrim($trimmed, '+-');

        $decimalPos = strpos($unsigned, '.');
        if ($decimalPos === false) {
            return;
        }

        $fraction = substr($unsigned, $decimalPos + 1);
        $beyondScale = substr($fraction, self::INVENTORY_SCALE);

        if ($beyondScale !== '' && (int) rtrim($beyondScale, '0') !== 0 && rtrim($beyondScale, '0') !== '') {
            throw new InvalidArgumentException(
                "{$context} value [{$sign}{$unsigned}] has non-zero digits beyond Inventory's scale-4 precision. Fail closed — never silently rounded."
            );
        }
    }
}
