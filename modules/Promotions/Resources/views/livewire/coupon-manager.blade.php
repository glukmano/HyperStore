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
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($coupons as $c)
                        <tr wire:key="coupon-{{ $c->id }}">
                            <td class="font-mono font-bold">{{ $c->code }}</td>
                            <td>{{ $c->promotion->name }}</td>
                            <td>{{ $c->usage_limit ?? 'Unlimited' }}</td>
                            <td>{{ $c->times_used }}</td>
                            <td><x-ui.badge variant="{{ $c->status === 'active' ? 'success' : 'ghost' }}">{{ $c->status }}</x-ui.badge></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="editCoupon({{ $c->id }})">Edit</button>
                                <button type="button" class="btn btn-xs btn-ghost" wire:click="toggleStatus({{ $c->id }})">
                                    {{ $c->status === 'active' ? 'Deactivate' : 'Activate' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4 text-base-content/50">No coupons found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <x-ui.modal :show="$editingId !== null" title="Edit Coupon" wireClose="cancelEdit">
        <form wire:submit.prevent="updateCoupon" class="space-y-4">
            <x-ui.input label="Coupon Code" wire:model="editCode" required />
            <x-ui.input label="Usage Limit (Optional)" type="number" wire:model="editUsageLimit" />
            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" type="button" wire:click="cancelEdit">Cancel</x-ui.button>
                <x-ui.button type="submit">Save Changes</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
