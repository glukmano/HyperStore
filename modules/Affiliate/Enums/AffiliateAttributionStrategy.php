<?php

declare(strict_types=1);

namespace Modules\Affiliate\Enums;

enum AffiliateAttributionStrategy: string
{
    case FirstClick = 'first_click';
    case LastClick = 'last_click';
    case Coupon = 'coupon';
    case Manual = 'manual';
}
