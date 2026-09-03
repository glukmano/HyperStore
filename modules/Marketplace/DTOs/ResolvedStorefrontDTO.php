<?php

declare(strict_types=1);

namespace Modules\Marketplace\DTOs;

use App\Core\Stores\Models\Store;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorStorefrontProfile;

final readonly class ResolvedStorefrontDTO
{
    public function __construct(
        public Vendor $vendor,
        public ?VendorStorefrontProfile $profile,
        public ?Store $store,
        public string $resolutionType,
        public ?string $canonicalUrl = null,
    ) {}
}
