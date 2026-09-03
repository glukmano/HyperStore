<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Enums;

enum FulfillmentMode: string
{
    case OWN_STOCK = 'own_stock';
    case VENDOR_STOCK = 'vendor_stock';
    case DROPSHIPPING = 'dropshipping';
    case THREE_PL = '3pl';
    case PRINT_ON_DEMAND = 'print_on_demand';
    case MADE_TO_ORDER = 'made_to_order';
    case DIGITAL = 'digital';
    case SERVICE = 'service';
    case HYBRID = 'hybrid';

    public function isPhysical(): bool
    {
        return in_array($this, [
            self::OWN_STOCK,
            self::VENDOR_STOCK,
            self::DROPSHIPPING,
            self::THREE_PL,
            self::PRINT_ON_DEMAND,
            self::MADE_TO_ORDER,
            self::HYBRID,
        ], true);
    }

    public function requiresSupplier(): bool
    {
        return in_array($this, [
            self::DROPSHIPPING,
            self::PRINT_ON_DEMAND,
            self::THREE_PL,
        ], true);
    }
}
