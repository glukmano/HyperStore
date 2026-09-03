<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Enums;

enum TenantOperationalStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function isActive(): bool
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
            self::Provisioning => in_array($target, [self::Active, self::Terminated], true),
            self::Active => in_array($target, [self::Suspended, self::Terminated], true),
            self::Suspended => in_array($target, [self::Active, self::Terminated], true),
            self::Terminated => false,
        };
    }
}
