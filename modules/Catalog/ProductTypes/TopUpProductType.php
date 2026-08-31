<?php

declare(strict_types=1);

namespace Modules\Catalog\ProductTypes;

use Modules\Catalog\Contracts\ProductTypeDefinition;

class TopUpProductType extends ProductTypeDefinition
{
    public function getId(): string
    {
        return 'topup';
    }

    public function getName(): string
    {
        return 'Top-Up / Recharge';
    }

    public function getDescription(): string
    {
        return 'Direct account credit and in-game currency recharges.';
    }

    public function supportsCustomerInput(): bool
    {
        return true;
    }
}
