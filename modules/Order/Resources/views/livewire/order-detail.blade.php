<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-base-content">Order {{ $order->order_number }}</h1>
            <p class="text-sm text-base-content/60">Placed {{ $order->placed_at?->format('Y-m-d H:i') }}</p>
        </div>
        <a href="{{ route('control-center.orders.orders.index') }}" class="btn btn-ghost btn-sm" wire:navigate>Back to Orders</a>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Orders' => route('control-center.orders.orders.index'), $order->order_number => null]" />

    <x-ui.stats :items="[
        ['label' => 'Order Status', 'value' => ucfirst($order->order_status)],
        ['label' => 'Payment Status', 'value' => ucfirst($order->payment_status)],
        ['label' => 'Fulfillment Status', 'value' => ucfirst($order->fulfillment_status)],
        ['label' => 'Grand Total', 'value' => number_format($order->grand_total_minor / 100, 2) . ' ' . $order->currency],
    ]" />

    <x-ui.card title="Line Items">
        <x-ui.table :headers="['SKU', 'Name', 'Qty', 'Unit Price', 'Vendor', 'Total']" :empty="$order->items->isEmpty()" emptyMessage="No line items.">
            @foreach ($order->items as $item)
                <tr>
                    <td class="font-mono text-xs">{{ $item->sku_snapshot }}</td>
                    <td>{{ $item->name_snapshot }}</td>
                    <td>{{ rtrim(rtrim((string) $item->quantity, '0'), '.') }}</td>
                    <td>{{ number_format($item->unit_price_minor / 100, 2) }}</td>
                    <td>{{ $item->vendor_name_snapshot ?? '—' }}</td>
                    <td>{{ number_format($item->total_minor / 100, 2) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Seller Orders & Fulfillments">
        @forelse ($order->sellerOrders as $sellerOrder)
            <div class="mb-4 border border-base-300 rounded-box p-4">
                <div class="flex items-center justify-between mb-2">
                    <div>
                        <span class="font-mono text-xs">{{ $sellerOrder->seller_order_number }}</span>
                        <span class="text-sm text-base-content/60">{{ $sellerOrder->vendor?->name ?? 'Platform' }}</span>
                    </div>
                    <x-ui.badge variant="{{ $sellerOrder->status === 'completed' ? 'success' : 'neutral' }}">{{ $sellerOrder->status }}</x-ui.badge>
                </div>

                @if ($sellerOrder->fulfillments->isNotEmpty())
                    <x-ui.table :headers="['Fulfillment #', 'Status', 'Carrier', 'Tracking']" :zebra="false">
                        @foreach ($sellerOrder->fulfillments as $fulfillment)
                            <tr>
                                <td class="font-mono text-xs">{{ $fulfillment->fulfillment_number ?? $fulfillment->id }}</td>
                                <td><x-ui.badge variant="ghost">{{ $fulfillment->status }}</x-ui.badge></td>
                                <td>{{ $fulfillment->carrier_code ?? '—' }}</td>
                                <td>{{ $fulfillment->tracking_number ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @else
                    <p class="text-sm text-base-content/50">No fulfillments yet.</p>
                @endif
            </div>
        @empty
            <x-ui.empty-state message="No seller orders for this order." />
        @endforelse
    </x-ui.card>

    <x-ui.card title="Returns / RMA">
        @forelse ($order->returnRequests as $returnRequest)
            <div class="mb-4 border border-base-300 rounded-box p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="font-mono text-xs">{{ $returnRequest->rma_number }}</span>
                    <x-ui.badge variant="ghost">{{ $returnRequest->overall_status }}</x-ui.badge>
                </div>
                @foreach ($returnRequest->sellerReturns as $sellerReturn)
                    <div class="ms-4 mb-2">
                        <span class="font-mono text-xs">{{ $sellerReturn->seller_rma_number }}</span>
                        <x-ui.badge variant="ghost">{{ $sellerReturn->status }}</x-ui.badge>
                    </div>
                @endforeach
            </div>
        @empty
            <x-ui.empty-state message="No returns for this order." />
        @endforelse
    </x-ui.card>
</div>
