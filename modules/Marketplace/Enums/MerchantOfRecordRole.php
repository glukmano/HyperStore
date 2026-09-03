<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum MerchantOfRecordRole: string
{
    case Platform = 'platform';
    case Seller = 'seller';
    case Agent = 'agent';
}
