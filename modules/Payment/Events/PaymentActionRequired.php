<?php

declare(strict_types=1);

namespace Modules\Payment\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Payment\DTOs\PaymentActionDTO;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;

class PaymentActionRequired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public PaymentTransaction $transaction,
        public PaymentActionDTO $action
    ) {}
}
