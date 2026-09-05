<?php

declare(strict_types=1);

namespace Modules\Affiliate\Enums;

enum AffiliateTargetType: string
{
    case Platform = 'platform';
    case Store = 'store';
    case Vendor = 'vendor';
    case Category = 'category';
    case Product = 'product';
}
