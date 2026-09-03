<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorOwnerInvariantViolationException extends MarketplaceException
{
    public static function cannotDemoteOrDeleteOwner(): self
    {
        return new self('The active vendor owner cannot be demoted, deactivated, or deleted through generic membership APIs. Use transferOwnership().');
    }

    public static function secondOwnerForbidden(): self
    {
        return new self('Vendor already has an active owner. Only one active owner is permitted.');
    }

    public static function targetUserAlreadyOwner(): self
    {
        return new self('Target user is already the active owner of this vendor.');
    }

    public static function targetUserNotMember(): self
    {
        return new self('Target user must be an existing vendor user or valid candidate before ownership transfer.');
    }
}
