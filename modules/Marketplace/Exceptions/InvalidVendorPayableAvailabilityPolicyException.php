<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class InvalidVendorPayableAvailabilityPolicyException extends MarketplaceException
{
    public static function invalidValue(string $scope, mixed $value, string $reason): self
    {
        $type = get_debug_type($value);

        return new self("Invalid payable hold policy ('marketplace.payable_hold_days') for {$scope}: value of type {$type} is {$reason}.");
    }

    public static function exceedsMaximum(string $scope, int $value, int $max): self
    {
        return new self("Payable hold policy ('marketplace.payable_hold_days') for {$scope} value [{$value}] exceeds maximum allowed [{$max}] days.");
    }
}
