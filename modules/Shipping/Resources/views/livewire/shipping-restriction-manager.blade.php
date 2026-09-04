<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Shipping Restrictions</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Geographic &amp; Product Delivery Restrictions">
        <x-ui.table :headers="['Type', 'Target Type', 'Target ID', 'Zone', 'Method', 'Reason']" :empty="$restrictions->isEmpty()" emptyMessage="No shipping restrictions found.">
            @foreach ($restrictions as $restriction)
                <tr wire:key="shipping-restriction-{{ $restriction->id }}">
                    <td><x-ui.badge variant="ghost">{{ $restriction->restriction_type }}</x-ui.badge></td>
                    <td>{{ $restriction->target_type }}</td>
                    <td>{{ $restriction->target_id }}</td>
                    <td>{{ $restriction->zone?->name ?? '—' }}</td>
                    <td>{{ $restriction->method?->name ?? '—' }}</td>
                    <td>{{ $restriction->reason ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
