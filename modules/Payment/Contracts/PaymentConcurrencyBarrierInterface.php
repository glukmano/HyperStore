<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

interface PaymentConcurrencyBarrierInterface
{
    public function wait(string $barrierName): void;

    public function signal(string $barrierName): void;
}
