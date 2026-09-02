<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use RuntimeException;

class OverCaptureException extends RuntimeException
{
    public static function forAmount(int $requested, int $remaining): self
    {
        return new self("Capture amount {$requested} exceeds remaining capturable amount {$remaining}.");
    }
}
