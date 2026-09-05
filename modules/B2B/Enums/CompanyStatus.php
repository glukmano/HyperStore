<?php

declare(strict_types=1);

namespace Modules\B2B\Enums;

enum CompanyStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
}
