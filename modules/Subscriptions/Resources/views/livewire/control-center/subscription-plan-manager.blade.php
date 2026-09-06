<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Subscription Plans</h1>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="Create Plan">
        <form wire:submit="createPlan" class="grid gap-4 sm:grid-cols-5 items-end">
            <div>
                <label class="label">Product ID</label>
                <input type="number" wire:model="productId" class="input input-bordered w-full" />
                @error('productId') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="label">Name</label>
                <input type="text" wire:model="name" class="input input-bordered w-full" />
                @error('name') <span class="text-error text-sm">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="label">Interval</label>
                <select wire:model="billingInterval" class="select select-bordered w-full">
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                    <option value="custom_days">Custom Days</option>
                </select>
            </div>
            <div>
                <label class="label">Interval Days</label>
                <input type="number" wire:model="billingIntervalDays" class="input input-bordered w-full" placeholder="n/a" />
            </div>
            <div>
                <label class="label">Trial Days</label>
                <input type="number" wire:model="trialDays" class="input input-bordered w-full" placeholder="none" />
            </div>
            <div class="sm:col-span-5">
                <x-ui.button type="submit" variant="primary">Create</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Existing Plans">
        <x-ui.table :headers="['Product', 'Name', 'Interval', 'Trial']" :empty="$plans->isEmpty()" emptyMessage="No plans yet.">
            @foreach ($plans as $plan)
                <tr wire:key="plan-{{ $plan->id }}">
                    <td>{{ $plan->product?->name }}</td>
                    <td>{{ $plan->name }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $plan->billing_interval)) }}</td>
                    <td>{{ $plan->trial_days ?? 'None' }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
