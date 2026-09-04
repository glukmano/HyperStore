<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Shipping Classes</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Shipping Class Classifications">
        <x-ui.table :headers="['Code', 'Name', 'Description']" :empty="$classes->isEmpty()" emptyMessage="No shipping classes found.">
            @foreach ($classes as $class)
                <tr wire:key="shipping-class-{{ $class->id }}">
                    <td class="font-medium">{{ $class->code }}</td>
                    <td>{{ $class->name }}</td>
                    <td>{{ $class->description ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
