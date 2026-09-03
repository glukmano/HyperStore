<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorInvitationException extends MarketplaceException
{
    public static function ownerRoleForbidden(): self
    {
        return new self("Invitations cannot grant the 'owner' role. Use atomic transferOwnership().");
    }

    public static function invalidToken(): self
    {
        return new self('The invitation token is invalid or has expired.');
    }

    public static function alreadyAccepted(): self
    {
        return new self('The invitation has already been accepted.');
    }

    public static function revoked(): self
    {
        return new self('The invitation has been revoked.');
    }

    public static function quotaExceeded(int $limit): self
    {
        return new self("Cannot invite staff: vendor plan staff limit of {$limit} has been reached.");
    }
}
