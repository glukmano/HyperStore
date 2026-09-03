<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\DTOs\CommissionQuoteDTO;

interface VendorCommissionQuoteServiceInterface
{
    public function quoteCommission(int $tenantId, int $vendorId, ?int $categoryId, int $basisMinor, string $currency): CommissionQuoteDTO;
}
