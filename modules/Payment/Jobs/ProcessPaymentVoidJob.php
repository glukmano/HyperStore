<?php

declare(strict_types=1);

namespace Modules\Payment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Payment\Services\PaymentCancellationService;

class ProcessPaymentVoidJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $paymentUuid,
        public readonly string $reason
    ) {}

    public function handle(PaymentCancellationService $cancellationService): void
    {
        $cancellationService->cancel(
            tenantId: $this->tenantId,
            paymentUuid: $this->paymentUuid,
            reason: $this->reason
        );
    }
}
