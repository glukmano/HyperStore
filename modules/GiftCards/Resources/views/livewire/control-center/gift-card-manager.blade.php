<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Gift Cards</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    @if ($lastIssuedCode)
        <x-ui.alert variant="warning">Newly issued code (shown once): <strong>{{ $lastIssuedCode }}</strong></x-ui.alert>
    @endif

    <x-ui.card title="Issue a Gift Card">
        <form wire:submit="issue" class="grid gap-4 sm:grid-cols-3 items-end">
            <div>
                <label class="label">Currency</label>
                <input type="text" wire:model="issueCurrency" class="input input-bordered w-full" maxlength="3" />
                @error('issueCurrency') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="label">Value</label>
                <input type="text" wire:model="issueValue" class="input input-bordered w-full" placeholder="0.00" />
                @error('issueValue') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <x-ui.button type="submit" variant="primary">Issue</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Issued Gift Cards">
        <x-ui.table :headers="['Last 4', 'Value', 'Status', 'Issued', '']" :empty="$cards->isEmpty()" emptyMessage="No Gift Cards issued yet.">
            @foreach ($cards as $card)
                <tr wire:key="gc-{{ $card->id }}">
                    <td>•••• {{ $card->code_last4 }}</td>
                    <td>{{ number_format($card->initial_value_minor / 100, 2) }} {{ $card->currency }}</td>
                    <td>{{ ucfirst($card->status) }}</td>
                    <td>{{ $card->created_at->format('Y-m-d') }}</td>
                    <td>
                        @if ($card->status !== 'deactivated')
                            <x-ui.button wire:click="deactivate({{ $card->id }})" variant="ghost" size="sm">Deactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
