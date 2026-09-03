<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Enums;

enum SupplierInvoiceReconciliationStatus: string
{
    case PENDING = 'pending';
    case MATCHED = 'matched';
    case DISCREPANCY = 'discrepancy';
    case REJECTED = 'rejected';
}
