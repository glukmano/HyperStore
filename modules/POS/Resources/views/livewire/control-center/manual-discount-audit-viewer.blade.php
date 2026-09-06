<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Manual Discount Audit Log</h1>

    <x-ui.card title="Entries">
        <x-ui.table :headers="['Session', 'Amount', 'Reason', 'Applied By', 'At']" :empty="$entries->isEmpty()" emptyMessage="No manual discounts recorded.">
            @foreach ($entries as $entry)
                <tr wire:key="disc-{{ $entry->id }}">
                    <td>{{ $entry->register_session_id }}</td>
                    <td>{{ number_format($entry->amount_minor / 100, 2) }}</td>
                    <td>{{ $entry->reason }}</td>
                    <td>{{ $entry->appliedBy->name ?? '—' }}</td>
                    <td>{{ $entry->created_at?->toDateTimeString() }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
