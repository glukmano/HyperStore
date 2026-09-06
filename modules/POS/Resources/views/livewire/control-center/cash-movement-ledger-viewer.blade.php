<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Cash Movement Ledger</h1>

    <x-ui.card title="Movements">
        <x-ui.table :headers="['Session', 'Type', 'Amount', 'Reason', 'By', 'At']" :empty="$movements->isEmpty()" emptyMessage="No cash movements yet.">
            @foreach ($movements as $m)
                <tr wire:key="mv-{{ $m->id }}">
                    <td>{{ $m->register_session_id }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $m->movement_type->value)) }}</td>
                    <td>{{ number_format($m->amount_minor / 100, 2) }} {{ $m->currency }}</td>
                    <td>{{ $m->reason }}</td>
                    <td>{{ $m->createdBy->name ?? '—' }}</td>
                    <td>{{ $m->created_at?->toDateTimeString() }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
