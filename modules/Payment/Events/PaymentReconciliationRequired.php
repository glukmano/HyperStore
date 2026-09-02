<?php

declare(strict_types=1);

namespace Modules\Payment\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Payment\Models\Payment;

class PaymentReconciliationRequired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public string $reason
    ) {}
}
