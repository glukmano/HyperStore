<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Affiliate Commission Rules</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Add Rule">
        <form wire:submit="createRule" class="grid gap-4 sm:grid-cols-2">
            <x-ui.select wire:model="affiliate_id" label="Affiliate (blank = platform default)">
                <option value="">— Default —</option>
                @foreach ($affiliates as $affiliate)
                    <option value="{{ $affiliate->id }}">{{ $affiliate->display_name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input wire:model="category_id" label="Category ID (optional)" type="number" />
            <x-ui.input wire:model="rate_basis_points" label="Rate (basis points)" type="number" error="{{ $errors->first('rate_basis_points') }}" />
            <x-ui.input wire:model="fixed_fee_minor" label="Fixed Fee (minor units)" type="number" error="{{ $errors->first('fixed_fee_minor') }}" />
            <x-ui.input wire:model="currency" label="Currency" placeholder="USD" error="{{ $errors->first('currency') }}" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Add Rule</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Rules">
        <x-ui.table :headers="['Affiliate', 'Category', 'Rate (bps)', 'Fixed Fee', 'Currency', 'Status', '']" :empty="$rules->isEmpty()" emptyMessage="No rules yet.">
            @foreach ($rules as $rule)
                <tr wire:key="rule-{{ $rule->id }}">
                    <td>{{ $rule->affiliate?->display_name ?? '— Default —' }}</td>
                    <td>{{ $rule->category_id ?? '—' }}</td>
                    <td>{{ $rule->rate_basis_points }}</td>
                    <td>{{ number_format($rule->fixed_fee_minor / 100, 2) }}</td>
                    <td>{{ $rule->currency }}</td>
                    <td><x-ui.badge variant="{{ $rule->is_active ? 'success' : 'ghost' }}">{{ $rule->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                    <td>
                        @if ($rule->is_active)
                            <x-ui.button wire:click="deactivate({{ $rule->id }})" variant="ghost" size="sm">Deactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
