<div class="max-w-2xl mx-auto space-y-6">
    @if($order === null)
        <x-ui.empty-state message="{{ __('Order not found.') }}" />
    @else
        <x-ui.alert variant="success">{{ __('Thank you! Your order has been placed.') }}</x-ui.alert>

        <x-ui.card :title="__('Order').' '.$order->order_number">
            <p class="text-sm text-base-content/60">{{ __('Status') }}: {{ $order->order_status }}</p>
            <x-ui.table :headers="[__('Item'), __('Qty'), __('Total')]" class="mt-4">
                @foreach($order->items as $item)
                    <tr>
                        <td>{{ $item->name_snapshot }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ number_format($item->total_minor / 100, 2) }} {{ $order->currency }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
