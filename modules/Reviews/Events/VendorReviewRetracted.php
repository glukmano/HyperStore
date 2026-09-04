<?php

declare(strict_types=1);

namespace Modules\Reviews\Events;

use Illuminate\Foundation\Events\Dispatchable;

class VendorReviewRetracted
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $vendorId,
    ) {}
}
