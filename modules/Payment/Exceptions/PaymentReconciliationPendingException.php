<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class PaymentReconciliationPendingException extends RuntimeException
{
    public static function forTransaction(int $transactionId): self
    {
        return new self("Payment transaction {$transactionId} is indeterminate (unknown) and requires out-of-band reconciliation.");
    }
}
