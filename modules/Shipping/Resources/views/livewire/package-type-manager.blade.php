<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Package Types</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Standard Packaging">
        <x-ui.table :headers="['Code', 'Name', 'Dimensions (L x W x H cm)', 'Max Weight (kg)', 'Tare Weight (kg)', 'Status']" :empty="$packageTypes->isEmpty()" emptyMessage="No package types found.">
            @foreach ($packageTypes as $packageType)
                <tr wire:key="package-type-{{ $packageType->id }}">
                    <td class="font-medium">{{ $packageType->code }}</td>
                    <td>{{ $packageType->name }}</td>
                    <td>{{ $packageType->length_cm }} x {{ $packageType->width_cm }} x {{ $packageType->height_cm }}</td>
                    <td>{{ $packageType->max_weight_kg }}</td>
                    <td>{{ $packageType->tare_weight_kg }}</td>
                    <td><x-ui.badge :variant="$packageType->status === 'active' ? 'success' : 'ghost'">{{ $packageType->status }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
