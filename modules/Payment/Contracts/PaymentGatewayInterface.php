<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

use Modules\Payment\DTOs\GatewayCaptureRequest;
use Modules\Payment\DTOs\GatewayPaymentRequest;
use Modules\Payment\DTOs\GatewayPaymentResult;
use Modules\Payment\DTOs\GatewayRefundRequest;
use Modules\Payment\DTOs\GatewayVoidRequest;

interface PaymentGatewayInterface
{
    public function getProviderCode(): string;

    public function supportsMethod(string $methodType): bool;

    public function purchase(GatewayPaymentRequest $request): GatewayPaymentResult;

    public function authorize(GatewayPaymentRequest $request): GatewayPaymentResult;

    public function capture(GatewayCaptureRequest $request): GatewayPaymentResult;

    public function refund(GatewayRefundRequest $request): GatewayPaymentResult;

    public function void(GatewayVoidRequest $request): GatewayPaymentResult;
}
