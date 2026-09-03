<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum PayoutBatchStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Completed = 'completed';
    case PartiallyFailed = 'partially_failed';
}
