<?php

declare(strict_types=1);

namespace Modules\B2B\Enums;

enum CompanyInvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';
}
