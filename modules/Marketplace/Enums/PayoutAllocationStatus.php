<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum PayoutAllocationStatus: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Consumed, self::Released], true);
    }
}
