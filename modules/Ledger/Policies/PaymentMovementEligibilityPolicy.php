<?php

declare(strict_types=1);

namespace Modules\Ledger\Policies;

use InvalidArgumentException;

final class PaymentMovementEligibilityPolicy
{
    /**
     * Determine whether a payment operation and transaction status qualify for ledger movement posting.
     */
    public function isEligible(string $operationType, string $status, int $amountMinor): bool
    {
        if ($status !== 'success') {
            return false;
        }

        if ($amountMinor <= 0) {
            return false;
        }

        return in_array($operationType, ['purchase', 'capture', 'refund'], true);
    }

    /**
     * Map payment provider operation types to standard double-entry posting types.
     */
    public function resolvePostingType(string $operationType): string
    {
        return match ($operationType) {
            'purchase', 'capture' => 'capture',
            'refund' => 'refund',
            default => throw new InvalidArgumentException("Operation type [{$operationType}] does not have an accounting posting type mapping."),
        };
    }
}
