<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class SubscriptionProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'subscription';
    }

    public function getName(): string
    {
        return 'Subscription Product';
    }

    public function getDescription(): string
    {
        return 'Recurring service or recurring physical deliveries.';
    }

    public function supportsRecurringBilling(): bool
    {
        return true;
    }

    public function supportsInventory(): bool
    {
        return true;
    }
}
