<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum VendorOperationalStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function canSell(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this === self::Terminated;
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        if ($this->isTerminal()) {
            return false;
        }

        return match ($this) {
            self::Draft => in_array($target, [self::PendingApproval, self::Terminated], true),
            self::PendingApproval => in_array($target, [self::Active, self::Draft, self::Suspended, self::Terminated], true),
            self::Active => in_array($target, [self::Suspended, self::Terminated], true),
            self::Suspended => in_array($target, [self::Active, self::Terminated], true),
            self::Terminated => false,
        };
    }
}
