<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Companies</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="New Company">
        <form wire:submit="createCompany" class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <x-ui.input label="Name" wire:model="name" :error="$errors->first('name')" />
            <x-ui.input label="Tax ID" wire:model="taxId" />
            <x-ui.select label="Customer Group" wire:model="customerGroupId">
                <option value="">None</option>
                @foreach ($customerGroups as $group)
                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input type="number" label="Payment Terms (days)" wire:model="paymentTermsDays" />
            <x-ui.input label="Credit Limit Currency" wire:model="creditLimitCurrency" />
            <x-ui.input type="number" label="Credit Limit (minor units)" wire:model="creditLimitMinor" />
            <div class="md:col-span-3">
                <x-ui.button type="submit" variant="primary">Create Company</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Companies">
        <x-ui.table :headers="['Name', 'Status', 'Terms', 'Credit Limit', '']" :empty="$companies->isEmpty()" emptyMessage="No companies yet.">
            @foreach ($companies as $company)
                <tr wire:key="company-{{ $company->id }}">
                    <td>{{ $company->name }}</td>
                    <td><x-ui.badge variant="{{ $company->status->value === 'active' ? 'success' : ($company->status->value === 'pending' ? 'warning' : 'ghost') }}">{{ ucfirst($company->status->value) }}</x-ui.badge></td>
                    <td>{{ $company->payment_terms_days !== null ? "Net {$company->payment_terms_days}" : '—' }}</td>
                    <td>{{ $company->credit_limit_minor !== null ? number_format($company->credit_limit_minor / 100, 2).' '.$company->credit_limit_currency : '—' }}</td>
                    <td class="flex gap-2">
                        @if ($company->status->value === 'pending')
                            <x-ui.button wire:click="approve({{ $company->id }})" variant="primary" size="sm">Approve</x-ui.button>
                        @elseif ($company->status->value === 'active')
                            <x-ui.button wire:click="suspend({{ $company->id }})" variant="ghost" size="sm">Suspend</x-ui.button>
                        @elseif ($company->status->value === 'suspended')
                            <x-ui.button wire:click="approve({{ $company->id }})" variant="primary" size="sm">Reactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
