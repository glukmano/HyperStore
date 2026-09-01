<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

final readonly class ShippingRateOutcome
{
    public const string SUCCESS = 'SUCCESS';

    public const string NO_METHOD_AVAILABLE = 'NO_METHOD_AVAILABLE';

    public const string PROVIDER_FAILURE = 'PROVIDER_FAILURE';

    public const string DESTINATION_RESTRICTED = 'DESTINATION_RESTRICTED';

    public const string UNFULFILLABLE_ITEMS = 'UNFULFILLABLE_ITEMS';

    public const string NO_SHIPPING_REQUIRED = 'NO_SHIPPING_REQUIRED';
}
