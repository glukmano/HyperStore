<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="{{ $editingId ? 'Edit Exchange Rate' : 'Set Exchange Rate' }}">
            @if (session('success'))
                <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
            @endif

            <form wire:submit.prevent="setRate" class="space-y-4">
                <x-ui.input label="Base Currency" wire:model="baseCurrency" placeholder="USD" required />
                <x-ui.input label="Target Currency" wire:model="targetCurrency" placeholder="EUR" required />
                <x-ui.input label="Rate" wire:model="rate" placeholder="0.92000000" required />

                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary w-full">{{ $editingId ? 'Save Changes' : 'Set Rate' }}</button>
                    @if ($editingId)
                        <button type="button" class="btn btn-ghost" wire:click="cancelEdit">Cancel</button>
                    @endif
                </div>
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
                        <th>Actions</th>
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
                            <td>
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="editRate({{ $r->id }})">Edit</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-base-content/50">No exchange rates configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
