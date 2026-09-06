<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Store Value Accounts</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Manual Adjustment">
        <form wire:submit="submitAdjustment" class="grid gap-4 sm:grid-cols-4 items-end">
            <div>
                <label class="label">Account ID</label>
                <input type="number" wire:model="adjustAccountId" class="input input-bordered w-full" />
                @error('adjustAccountId') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="label">Direction</label>
                <select wire:model="adjustDirection" class="select select-bordered w-full">
                    <option value="credit">Credit</option>
                    <option value="debit">Debit</option>
                </select>
            </div>
            <div>
                <label class="label">Amount</label>
                <input type="text" wire:model="adjustAmount" class="input input-bordered w-full" placeholder="0.00" />
                @error('adjustAmount') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="label">Reason (audit)</label>
                <input type="text" wire:model="adjustReason" class="input input-bordered w-full" />
                @error('adjustReason') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="sm:col-span-4">
                <x-ui.button type="submit" variant="primary">Apply Adjustment</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Accounts">
        <x-ui.table :headers="['ID', 'Customer', 'Instrument', 'Currency', 'Balance', 'Status']" :empty="$accounts->isEmpty()" emptyMessage="No Store Value accounts yet.">
            @foreach ($accounts as $account)
                <tr wire:key="sva-{{ $account->id }}">
                    <td>{{ $account->id }}</td>
                    <td>{{ $account->customerProfile?->user?->email ?? 'Unclaimed' }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $account->instrument_type->value)) }}</td>
                    <td>{{ $account->currency }}</td>
                    <td>{{ number_format($balances[$account->id] / 100, 2) }}</td>
                    <td>{{ ucfirst($account->status) }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
