<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Fulfillment\Models\OrderFulfillment;
use Modules\Fulfillment\Models\OrderShipment;
use Modules\Order\Models\SellerOrder;

interface FulfillmentExecutionServiceInterface
{
    /**
     * @param list<array{
     *     mode: string,
     *     supplier_id?: ?int,
     *     supplier_location_id?: ?int,
     *     inventory_location_id?: ?int,
     *     items: list<array{order_item_id: int, quantity: string}>,
     *     children?: list<array{
     *         mode: string,
     *         supplier_id?: ?int,
     *         supplier_location_id?: ?int,
     *         inventory_location_id?: ?int,
     *         items: list<array{order_item_id: int, quantity: string}>
     *     }>
     * }> $groups
     * @return Collection<int, OrderFulfillment>
     */
    public function createFulfillments(SellerOrder $sellerOrder, array $groups): Collection;

    public function shipFulfillment(
        OrderFulfillment $fulfillment,
        string $carrierCode,
        string $trackingNumber,
        ?string $trackingUrl = null
    ): OrderShipment;
}
