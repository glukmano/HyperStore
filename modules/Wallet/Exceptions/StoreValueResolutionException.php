<?php

declare(strict_types=1);

namespace Modules\Wallet\Exceptions;

/**
 * A hold is resolved exactly once, by either capture OR release, never
 * both — mirrors CompanyCreditEntry's reservation/release/settlement
 * discipline (ADR-0144) applied to Store Value holds.
 */
class StoreValueResolutionException extends WalletException
{
    public static function alreadyResolved(int $holdEntryId): self
    {
        return new self("Store Value hold entry [{$holdEntryId}] has already been resolved by a capture or release — cannot resolve it a second time.");
    }

    public static function notFound(string $sourceUuid): self
    {
        return new self("No Store Value hold entry found for source_uuid [{$sourceUuid}].");
    }
}
