<?php

declare(strict_types=1);

namespace Modules\POS\Enums;

enum RegisterSessionStatus: string
{
    case ACTIVE = 'active';
    case CLOSED = 'closed';
}
