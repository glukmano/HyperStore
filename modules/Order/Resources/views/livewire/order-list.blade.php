<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-base-content">Orders</h1>
            <p class="text-sm text-base-content/60">Placed orders across this tenant</p>
        </div>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Orders' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="mb-4 md:w-64">
            <x-ui.select wire:model.live="statusFilter" placeholder="All Statuses">
                @foreach ($statuses as $status)
                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <x-ui.table :headers="['Order #', 'Status', 'Payment', 'Fulfillment', 'Grand Total', 'Placed At', '']" :empty="$orders->isEmpty()" emptyMessage="No orders found.">
            @foreach ($orders as $order)
                <tr>
                    <td class="font-mono text-xs">{{ $order->order_number }}</td>
                    <td><x-ui.badge variant="{{ $order->order_status === 'completed' ? 'success' : ($order->order_status === 'cancelled' ? 'danger' : 'neutral') }}">{{ $order->order_status }}</x-ui.badge></td>
                    <td><x-ui.badge variant="{{ $order->payment_status === 'paid' ? 'success' : 'warning' }}">{{ $order->payment_status }}</x-ui.badge></td>
                    <td><x-ui.badge variant="{{ $order->fulfillment_status === 'fulfilled' ? 'success' : 'ghost' }}">{{ $order->fulfillment_status }}</x-ui.badge></td>
                    <td>{{ number_format($order->grand_total_minor / 100, 2) }} {{ $order->currency }}</td>
                    <td>{{ $order->placed_at?->format('Y-m-d H:i') }}</td>
                    <td class="text-end">
                        <a href="{{ route('control-center.orders.orders.show', $order->id) }}" class="btn btn-sm btn-ghost" wire:navigate>View</a>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$orders" class="mt-4" />
    </x-ui.card>
</div>
