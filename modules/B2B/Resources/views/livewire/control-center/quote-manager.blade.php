<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Quotes / RFQ</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Quotes">
        <x-ui.table :headers="['Company', 'Status', 'Currency', 'Lines', '']" :empty="$quotes->isEmpty()" emptyMessage="No quotes yet.">
            @foreach ($quotes as $quote)
                <tr wire:key="quote-{{ $quote->id }}">
                    <td>{{ $quote->company->name }}</td>
                    <td><x-ui.badge variant="{{ $quote->status->value === 'accepted' ? 'success' : ($quote->status->value === 'submitted' ? 'warning' : 'ghost') }}">{{ ucfirst($quote->status->value) }}</x-ui.badge></td>
                    <td>{{ $quote->currency }}</td>
                    <td>{{ $quote->lines->count() }}</td>
                    <td class="flex gap-2">
                        @if (in_array($quote->status->value, ['submitted', 'quoted']))
                            <x-ui.button wire:click="editQuote({{ $quote->id }})" variant="primary" size="sm">Set Prices</x-ui.button>
                            <x-ui.button wire:click="reject({{ $quote->id }})" variant="ghost" size="sm">Reject</x-ui.button>
                        @endif
                    </td>
                </tr>
                @if ($editingQuoteId === $quote->id)
                    <tr>
                        <td colspan="5">
                            <form wire:submit="savePrices" class="space-y-2 p-3 bg-base-200 rounded-box">
                                @foreach ($quote->lines as $line)
                                    <div class="flex items-center gap-3">
                                        <span class="w-40 text-sm">{{ $line->product?->sku }} × {{ $line->quantity }}</span>
                                        <x-ui.input type="number" label="Negotiated unit price (minor)" wire:model="lineePrices.{{ $line->id }}" />
                                    </div>
                                @endforeach
                                <x-ui.button type="submit" variant="primary" size="sm">Save & Send Quote</x-ui.button>
                            </form>
                        </td>
                    </tr>
                @endif
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
