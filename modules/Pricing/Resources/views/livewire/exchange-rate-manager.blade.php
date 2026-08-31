<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Set Exchange Rate">
            <form wire:submit.prevent="setRate" class="space-y-4">
                <x-ui.input label="Base Currency" wire:model="baseCurrency" placeholder="USD" required />
                <x-ui.input label="Target Currency" wire:model="targetCurrency" placeholder="EUR" required />
                <x-ui.input label="Rate" wire:model="rate" placeholder="0.92000000" required />

                <button type="submit" class="btn btn-primary w-full">Set Rate</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Configured Exchange Rates">
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Base</th>
                        <th>Target</th>
                        <th>Rate</th>
                        <th>Source</th>
                        <th>Effective At</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rates as $r)
                        <tr>
                            <td><x-ui.badge>{{ $r->base_currency }}</x-ui.badge></td>
                            <td><x-ui.badge variant="secondary">{{ $r->target_currency }}</x-ui.badge></td>
                            <td class="font-mono font-bold">{{ $r->rate }}</td>
                            <td>{{ $r->source }}</td>
                            <td class="text-xs text-base-content/60">{{ $r->effective_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-4 text-base-content/50">No exchange rates configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
