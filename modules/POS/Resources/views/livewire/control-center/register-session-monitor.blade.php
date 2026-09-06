<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Register Sessions</h1>

    <x-ui.card title="Sessions">
        <x-ui.table :headers="['Register', 'Cashier', 'Status', 'Opening', 'Counted', 'Expected', 'Variance', 'Opened', 'Closed']" :empty="$sessions->isEmpty()" emptyMessage="No sessions yet.">
            @foreach ($sessions as $s)
                <tr wire:key="sess-{{ $s->id }}">
                    <td>{{ $s->register->code }}</td>
                    <td>{{ $s->cashier->name ?? '—' }}</td>
                    <td>{{ ucfirst($s->status->value) }}</td>
                    <td>{{ number_format($s->opening_cash_minor / 100, 2) }}</td>
                    <td>{{ $s->closing_cash_counted_minor !== null ? number_format($s->closing_cash_counted_minor / 100, 2) : '—' }}</td>
                    <td>{{ $s->closing_cash_expected_minor !== null ? number_format($s->closing_cash_expected_minor / 100, 2) : '—' }}</td>
                    <td>{{ $s->closing_variance_minor !== null ? number_format($s->closing_variance_minor / 100, 2) : '—' }}</td>
                    <td>{{ $s->opened_at?->toDateTimeString() }}</td>
                    <td>{{ $s->closed_at?->toDateTimeString() ?? '—' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
