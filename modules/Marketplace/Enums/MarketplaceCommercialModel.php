<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum MarketplaceCommercialModel: string
{
    case PlatformAsMerchantOfRecord = 'platform_as_merchant_of_record';
    case SellerAsMerchantOfRecord = 'seller_as_merchant_of_record';
    case MarketplaceAgent = 'marketplace_agent';

    public function doesPlatformCollectCustomerFunds(): bool
    {
        return match ($this) {
            self::PlatformAsMerchantOfRecord, self::MarketplaceAgent => true,
            self::SellerAsMerchantOfRecord => false,
        };
    }

    public function doesPlatformOweVendorPayable(): bool
    {
        return match ($this) {
            self::PlatformAsMerchantOfRecord, self::MarketplaceAgent => true,
            self::SellerAsMerchantOfRecord => false,
        };
    }

    public function doesPlatformRecognizeCommission(): bool
    {
        return true;
    }

    public function merchantOfRecordRole(): MerchantOfRecordRole
    {
        return match ($this) {
            self::PlatformAsMerchantOfRecord => MerchantOfRecordRole::Platform,
            self::SellerAsMerchantOfRecord => MerchantOfRecordRole::Seller,
            self::MarketplaceAgent => MerchantOfRecordRole::Agent,
        };
    }
}
