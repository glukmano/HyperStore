<?php

declare(strict_types=1);

namespace Modules\Affiliate\Enums;

enum AffiliateConversionStatus: string
{
    case Pending = 'pending';
    case Accrued = 'accrued';
    case Reversed = 'reversed';
}
