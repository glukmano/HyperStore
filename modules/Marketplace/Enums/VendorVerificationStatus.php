<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum VendorVerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case NeedsReview = 'needs_review';

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Unverified => in_array($target, [self::Pending, self::Verified, self::NeedsReview], true),
            self::Pending => in_array($target, [self::Verified, self::Rejected, self::NeedsReview], true),
            self::Verified => in_array($target, [self::NeedsReview, self::Pending, self::Rejected], true),
            self::Rejected => in_array($target, [self::Pending, self::NeedsReview], true),
            self::NeedsReview => in_array($target, [self::Verified, self::Rejected, self::Pending], true),
        };
    }
}
