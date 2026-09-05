<?php

declare(strict_types=1);

namespace Modules\B2B\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
