<?php

declare(strict_types=1);

namespace App\Core\Payables\Enums;

enum PayoutBatchStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Completed = 'completed';
    case PartiallyFailed = 'partially_failed';
}
