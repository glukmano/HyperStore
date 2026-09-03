<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum VendorRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    public function canManageStaff(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }

    public function canManageFinances(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }
}
