<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Suppliers' => route('control-center.dropshipping.suppliers.index'), $supplier->name => null]" />

    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">{{ $supplier->name }}</h1>
        <x-ui.badge variant="{{ match ($supplier->status) {
            'active' => 'success',
            'inactive' => 'ghost',
            'suspended' => 'danger',
            default => 'neutral',
        } }}">
            {{ $supplier->status }}
        </x-ui.badge>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.stats :items="[
        ['label' => 'Scope', 'value' => $supplier->scope_type],
        ['label' => 'Dropship Capable', 'value' => $supplier->is_dropship_capable ? 'Yes' : 'No'],
        ['label' => 'Lead Time', 'value' => $supplier->lead_time_days . ' days'],
        ['label' => 'Rating', 'value' => (string) $supplier->rating_score],
    ]" />

    <x-ui.card title="Supplier Details">
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <dt class="text-xs uppercase text-base-content/60">Code</dt>
                <dd class="font-mono text-sm">{{ $supplier->code }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Contact Email</dt>
                <dd class="font-medium">{{ $supplier->contact_email }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Contact Phone</dt>
                <dd class="font-medium">{{ $supplier->contact_phone ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Currency</dt>
                <dd class="font-medium">{{ $supplier->currency }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Minimum Order Value</dt>
                <dd class="font-medium">{{ number_format($supplier->min_order_value_minor / 100, 2) }} {{ $supplier->currency }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase text-base-content/60">Vendor (Private Scope Owner)</dt>
                <dd class="font-medium">{{ $supplier->vendor?->name ?? '—' }}</dd>
            </div>
        </dl>
    </x-ui.card>

    <x-ui.card title="Recent Purchase Orders">
        <x-ui.table :headers="['PO Number', 'Status', 'Total', 'Submitted', 'Expected', 'Delivered', '']" :empty="$recentPurchaseOrders->isEmpty()" emptyMessage="No purchase orders for this supplier yet.">
            @foreach ($recentPurchaseOrders as $po)
                <tr wire:key="po-{{ $po->id }}">
                    <td class="font-mono text-xs">{{ $po->po_number }}</td>
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
    </x-ui.card>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <x-ui.card title="Locations">
            <x-ui.table :headers="['Code', 'Name', 'City', 'Country', 'Active']" :empty="$locations->isEmpty()" emptyMessage="No locations for this supplier.">
                @foreach ($locations as $location)
                    <tr wire:key="location-{{ $location->id }}">
                        <td class="font-mono text-xs">{{ $location->code }}</td>
                        <td>{{ $location->name }}</td>
                        <td>{{ $location->city }}</td>
                        <td>{{ $location->country_code }}</td>
                        <td>
                            @if ($location->is_active)
                                <x-ui.badge variant="success">Active</x-ui.badge>
                            @else
                                <x-ui.badge variant="ghost">Inactive</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Offers">
            <x-ui.table :headers="['Supplier SKU', 'Location', 'Stock', 'Available']" :empty="$offers->isEmpty()" emptyMessage="No offers for this supplier.">
                @foreach ($offers as $offer)
                    <tr wire:key="offer-{{ $offer->id }}">
                        <td class="font-mono text-xs">{{ $offer->supplierProductVariant?->supplier_sku ?? '—' }}</td>
                        <td>{{ $offer->supplierLocation?->name ?? '—' }}</td>
                        <td>{{ $offer->stock_quantity }}</td>
                        <td>
                            @if ($offer->is_available)
                                <x-ui.badge variant="success">Yes</x-ui.badge>
                            @else
                                <x-ui.badge variant="ghost">No</x-ui.badge>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>
</div>
