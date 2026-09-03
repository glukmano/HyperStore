<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use DomainException;

class UnsupportedCommercialModelException extends DomainException
{
    public static function deferredRuntime(string $model): self
    {
        return new self("UNSUPPORTED_COMMERCIAL_MODEL_RUNTIME: Commercial model [{$model}] requires runtime gateway routing deferred to Phase-15.");
    }
}
