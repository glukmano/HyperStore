<div class="space-y-6">
    <h2 class="text-xl font-bold">Shipping Methods</h2>
    <form wire:submit="createMethod" class="bg-base-100 p-4 rounded-lg shadow space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <input type="text" wire:model="code" placeholder="Method Code" class="input input-bordered" required />
            <input type="text" wire:model="name" placeholder="Method Name" class="input input-bordered" required />
            <select wire:model="rateCalculatorType" class="select select-bordered">
                <option value="flat_rate">Flat Rate</option>
                <option value="free_shipping">Free Shipping</option>
                <option value="weight_based">Weight Based</option>
                <option value="table_rate">Table Rate</option>
                <option value="local_pickup">Local Pickup</option>
                <option value="local_delivery">Local Delivery</option>
                <option value="carrier_calculated">Carrier Calculated</option>
            </select>
            <input type="text" wire:model="currency" placeholder="Currency (CHF)" class="input input-bordered" required />
            <input type="number" wire:model="baseAmount" placeholder="Base Amount (minor)" class="input input-bordered" required />
            <input type="number" wire:model="handlingFee" placeholder="Handling Fee (minor)" class="input input-bordered" />
            <input type="number" wire:model="priority" placeholder="Priority" class="input input-bordered" />
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Create Method</button>
    </form>
    <div class="overflow-x-auto">
        <table class="table w-full">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Base Amount</th>
                    <th>Currency</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($methods as $method)
                    <tr>
                        <td>{{ $method->code }}</td>
                        <td>{{ $method->name }}</td>
                        <td>{{ $method->rate_calculator_type }}</td>
                        <td>{{ $method->base_amount }}</td>
                        <td>{{ $method->currency }}</td>
                        <td><span class="badge badge-success">{{ $method->status }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center">No shipping methods found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
