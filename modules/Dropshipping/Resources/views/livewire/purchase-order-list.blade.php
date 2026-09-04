<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Purchase Orders' => null]" />

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-base-content">Purchase Orders</h1>
            <p class="text-sm text-base-content/60">Dropship purchase orders across this tenant</p>
        </div>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="mb-4 md:w-64">
            <x-ui.select wire:model.live="statusFilter" placeholder="All Statuses">
                @foreach ($statuses as $status)
                    <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <x-ui.table :headers="['PO Number', 'Supplier', 'Status', 'Total', 'Submitted', 'Expected', 'Delivered', '']" :empty="$purchaseOrders->isEmpty()" emptyMessage="No purchase orders found.">
            @foreach ($purchaseOrders as $po)
                <tr wire:key="po-{{ $po->id }}">
                    <td class="font-mono text-xs">{{ $po->po_number }}</td>
                    <td>{{ $po->supplier?->name ?? '—' }}</td>
                    <td>
                        <x-ui.badge variant="{{ match ($po->status) {
                            'draft' => 'ghost',
                            'submitted' => 'accent',
                            'confirmed' => 'primary',
                            'fulfilled' => 'success',
                            'cancelled' => 'danger',
                            'rejected' => 'danger',
                            default => 'neutral',
                        } }}">
                            {{ $po->status }}
                        </x-ui.badge>
                    </td>
                    <td>{{ number_format($po->total_minor / 100, 2) }} {{ $po->currency }}</td>
                    <td>{{ $po->submitted_at?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $po->expected_at?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $po->delivered_at?->format('Y-m-d') ?? '—' }}</td>
                    <td class="text-end">
                        <a href="{{ route('control-center.dropshipping.purchase-orders.show', ['purchaseOrderId' => $po->id]) }}" wire:navigate class="btn btn-ghost btn-sm">View</a>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$purchaseOrders" />
    </x-ui.card>
</div>
