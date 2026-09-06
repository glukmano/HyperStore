<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">My Wallet & Store Credit</h1>

    <div class="grid gap-4 sm:grid-cols-3">
        @forelse ($accounts as $account)
            <x-ui.card title="{{ ucfirst(str_replace('_', ' ', $account->instrument_type->value)) }}">
                <p class="text-3xl font-bold">{{ number_format($balances[$account->id] / 100, 2) }} {{ $account->currency }}</p>
            </x-ui.card>
        @empty
            <p class="text-base-content/60">No Wallet or Store Credit balance yet.</p>
        @endforelse
    </div>

    <x-ui.card title="Transaction History">
        <x-ui.table :headers="['Date', 'Type', 'Instrument', 'Amount']" :empty="$entries->isEmpty()" emptyMessage="No transactions yet.">
            @foreach ($entries as $entry)
                <tr wire:key="sve-{{ $entry->id }}">
                    <td>{{ $entry->created_at->format('Y-m-d H:i') }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $entry->entry_type->value)) }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $entry->instrument_type->value)) }}</td>
                    <td>{{ number_format($entry->amount_minor / 100, 2) }} {{ $entry->currency }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
