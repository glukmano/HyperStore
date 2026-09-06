<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Exceptions;

class DownloadLimitExceededException extends DigitalDeliveryException
{
    public static function forEntitlement(int $entitlementId): self
    {
        return new self("Entitlement [{$entitlementId}] has exhausted its allowed download count.");
    }
}
