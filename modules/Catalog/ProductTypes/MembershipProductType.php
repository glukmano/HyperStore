<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class MembershipProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'membership';
    }

    public function getName(): string
    {
        return 'Membership Tier';
    }

    public function getDescription(): string
    {
        return 'Exclusive customer membership and VIP platform access.';
    }

    public function supportsRecurringBilling(): bool
    {
        return true;
    }
}
