<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Loyalty Program</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.card title="Program Settings">
        <form wire:submit="saveProgram" class="space-y-4 max-w-lg">
            <x-ui.input label="Name" wire:model="name" :error="$errors->first('name')" />

            <label class="label cursor-pointer justify-start gap-3">
                <input type="checkbox" wire:model="isActive" class="checkbox" />
                <span class="label-text">Active</span>
            </label>

            <x-ui.input type="number" label="Pending hold period (days before earned points become available)" wire:model="pendingHoldDays" :error="$errors->first('pendingHoldDays')" />
            <x-ui.input type="number" label="Points expire after (days, blank = never)" wire:model="pointsExpireAfterDays" :error="$errors->first('pointsExpireAfterDays')" />
            <x-ui.input type="number" label="Customer referral reward (points)" wire:model="referralRewardPoints" :error="$errors->first('referralRewardPoints')" />

            <x-ui.button type="submit" variant="primary">Save</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card title="Per-Currency Earn / Redemption Rules">
        <x-ui.table :headers="['Currency', 'Earn rate', 'Redemption value', 'Active', '']" :empty="$rules->isEmpty()" emptyMessage="No currency rules yet.">
            @foreach ($rules as $rule)
                <tr wire:key="rule-{{ $rule->id }}">
                    <td>{{ $rule->currency }}</td>
                    <td>1 point per {{ number_format($rule->minor_units_per_point / 100, 2) }} spent</td>
                    <td>{{ number_format($rule->point_redemption_value_minor / 100, 2) }} per point</td>
                    <td><x-ui.badge variant="{{ $rule->is_active ? 'success' : 'ghost' }}">{{ $rule->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                    <td>
                        <x-ui.button wire:click="toggleRule({{ $rule->id }})" variant="ghost" size="sm">
                            {{ $rule->is_active ? 'Deactivate' : 'Activate' }}
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <form wire:submit="saveCurrencyRule" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            <x-ui.input label="Currency (ISO-3)" wire:model="ruleCurrency" :error="$errors->first('ruleCurrency')" />
            <x-ui.input type="number" label="Minor units per point (earn rate)" wire:model="ruleMinorUnitsPerPoint" :error="$errors->first('ruleMinorUnitsPerPoint')" />
            <x-ui.input type="number" label="Redemption value (minor units per point)" wire:model="ruleRedemptionValueMinor" :error="$errors->first('ruleRedemptionValueMinor')" />
            <x-ui.button type="submit" variant="primary">Save Rule</x-ui.button>
        </form>
    </x-ui.card>
</div>
