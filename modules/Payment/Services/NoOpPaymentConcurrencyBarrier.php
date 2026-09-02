<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Modules\Payment\Contracts\PaymentConcurrencyBarrierInterface;

class NoOpPaymentConcurrencyBarrier implements PaymentConcurrencyBarrierInterface
{
    public function wait(string $barrierName): void {}

    public function signal(string $barrierName): void {}
}
