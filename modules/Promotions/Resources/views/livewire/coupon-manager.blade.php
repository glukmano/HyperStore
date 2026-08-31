<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="md:col-span-1 space-y-4">
        <x-ui.card title="Generate Coupon">
            <form wire:submit.prevent="createCoupon" class="space-y-4">
                <div class="form-control">
                    <label class="label"><span class="label-text">Linked Promotion</span></label>
                    <select wire:model="promotionId" class="select select-bordered w-full" required>
                        <option value="">-- Choose Promotion --</option>
                        @foreach ($promotions as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>

                <x-ui.input label="Coupon Code" wire:model="code" placeholder="SAVE20" required />
                <x-ui.input label="Usage Limit (Optional)" type="number" wire:model="usageLimit" />

                <button type="submit" class="btn btn-primary w-full">Create Coupon</button>
            </form>
        </x-ui.card>
    </div>

    <div class="md:col-span-2">
        <x-ui.card title="Active Coupons">
            <table class="table w-full">
                <thead>
                    <tr>
                        <th>Coupon Code</th>
                        <th>Promotion</th>
                        <th>Usage Limit</th>
                        <th>Times Used</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($coupons as $c)
                        <tr>
                            <td class="font-mono font-bold">{{ $c->code }}</td>
                            <td>{{ $c->promotion->name }}</td>
                            <td>{{ $c->usage_limit ?? 'Unlimited' }}</td>
                            <td>{{ $c->times_used }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-4 text-base-content/50">No coupons found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
