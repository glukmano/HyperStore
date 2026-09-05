<?php

declare(strict_types=1);

namespace Modules\B2B\Enums;

/**
 * Owner Delta §2: Company-local purchasing authority — decided ENTIRELY by
 * this role on the authenticated User's own CompanyUser row, never by a
 * global spatie/laravel-permission grant. A User's role in one Company
 * confers zero authority in any other Company.
 */
enum CompanyUserRole: string
{
    case Owner = 'owner';
    case Buyer = 'buyer';
    case Approver = 'approver';

    public function canApproveQuotes(): bool
    {
        return $this === self::Owner || $this === self::Approver;
    }

    public function canManageCompany(): bool
    {
        return $this === self::Owner;
    }
}
