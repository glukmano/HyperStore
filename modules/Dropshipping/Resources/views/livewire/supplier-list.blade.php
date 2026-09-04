<div class="space-y-6">
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Suppliers' => null]" />

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-base-content">Suppliers</h1>
            <p class="text-sm text-base-content/60">Dropship suppliers available to this tenant</p>
        </div>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.card title="All Suppliers">
        <x-ui.table :headers="['Name', 'Code', 'Status', 'Dropship Capable', 'Lead Time', 'Rating', '']" :empty="$suppliers->isEmpty()" emptyMessage="No suppliers found.">
            @foreach ($suppliers as $supplier)
                <tr wire:key="supplier-{{ $supplier->id }}">
                    <td class="font-medium">{{ $supplier->name }}</td>
                    <td class="font-mono text-xs">{{ $supplier->code }}</td>
                    <td>
                        <x-ui.badge variant="{{ match ($supplier->status) {
                            'active' => 'success',
                            'inactive' => 'ghost',
                            'suspended' => 'danger',
                            default => 'neutral',
                        } }}">
                            {{ $supplier->status }}
                        </x-ui.badge>
                    </td>
                    <td>
                        @if ($supplier->is_dropship_capable)
                            <x-ui.badge variant="success">Yes</x-ui.badge>
                        @else
                            <x-ui.badge variant="ghost">No</x-ui.badge>
                        @endif
                    </td>
                    <td>{{ $supplier->lead_time_days }} {{ $supplier->lead_time_days === 1 ? 'day' : 'days' }}</td>
                    <td>{{ $supplier->rating_score }}</td>
                    <td class="text-end">
                        <a href="{{ route('control-center.dropshipping.suppliers.show', ['supplierId' => $supplier->id]) }}" wire:navigate class="btn btn-ghost btn-sm">View</a>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <x-ui.pagination :paginator="$suppliers" />
    </x-ui.card>
</div>
