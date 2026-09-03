<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Enums;

enum SupplierScope: string
{
    case PLATFORM = 'platform';
    case TENANT = 'tenant';
    case PRIVATE_VENDOR = 'private_vendor';
}
