<?php

declare(strict_types=1);

namespace Modules\Ledger\Enums;

enum AccountStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}
