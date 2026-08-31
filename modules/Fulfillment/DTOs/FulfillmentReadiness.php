<?php

declare(strict_types=1);

namespace Modules\Fulfillment\DTOs;

final class FulfillmentReadiness
{
    public const string READY = 'ready';

    public const string PARTIAL = 'partial';

    public const string BACKORDERED = 'backordered';

    public const string PREORDER = 'preorder';

    public const string UNAVAILABLE = 'unavailable';

    public const string NON_PHYSICAL = 'non_physical';
}
