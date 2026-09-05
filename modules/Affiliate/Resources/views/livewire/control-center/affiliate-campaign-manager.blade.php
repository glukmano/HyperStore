<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Affiliate Campaigns</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card title="New Campaign">
        <form wire:submit="createCampaign" class="grid gap-4 sm:grid-cols-2">
            <x-ui.input wire:model="name" label="Name" error="{{ $errors->first('name') }}" />
            <x-ui.select wire:model="target_type" label="Target Type">
                @foreach ($targetTypes as $type)
                    <option value="{{ $type->value }}">{{ ucfirst($type->value) }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input wire:model="target_id" label="Target ID (blank for platform)" type="number" error="{{ $errors->first('target_id') }}" />
            <x-ui.select wire:model="attribution_strategy" label="Attribution Strategy">
                @foreach ($strategies as $strategy)
                    <option value="{{ $strategy->value }}">{{ str_replace('_', ' ', $strategy->value) }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input wire:model="attribution_window_days" label="Attribution Window (days)" type="number" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Create Campaign</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Campaigns">
        <x-ui.table :headers="['Name', 'Target', 'Strategy', 'Window (days)', 'Status', '']" :empty="$campaigns->isEmpty()" emptyMessage="No campaigns yet.">
            @foreach ($campaigns as $campaign)
                <tr wire:key="camp-{{ $campaign->id }}">
                    <td>{{ $campaign->name }}</td>
                    <td>{{ ucfirst($campaign->target_type->value) }}{{ $campaign->target_id ? ' #'.$campaign->target_id : '' }}</td>
                    <td>{{ str_replace('_', ' ', $campaign->attribution_strategy->value) }}</td>
                    <td>{{ $campaign->attribution_window_days }}</td>
                    <td><x-ui.badge variant="{{ $campaign->is_active ? 'success' : 'ghost' }}">{{ $campaign->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                    <td>
                        @if ($campaign->is_active)
                            <x-ui.button wire:click="deactivate({{ $campaign->id }})" variant="ghost" size="sm">Deactivate</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Generate Referral Code">
        <form wire:submit="generateReferralCode" class="grid gap-4 sm:grid-cols-3">
            <x-ui.select wire:model="referral_affiliate_id" label="Affiliate" error="{{ $errors->first('referral_affiliate_id') }}">
                <option value="">— Select —</option>
                @foreach ($affiliates as $affiliate)
                    <option value="{{ $affiliate->id }}">{{ $affiliate->display_name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select wire:model="referral_campaign_id" label="Campaign (optional)">
                <option value="">— None (platform) —</option>
                @foreach ($campaigns as $campaign)
                    <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                @endforeach
            </x-ui.select>
            <div class="flex items-end">
                <x-ui.button type="submit" variant="primary">Generate Code</x-ui.button>
            </div>
        </form>

        <x-ui.table :headers="['Code', 'Affiliate', 'Target', 'Status']" :empty="$referralCodes->isEmpty()" emptyMessage="No referral codes yet." class="mt-4">
            @foreach ($referralCodes as $code)
                <tr wire:key="code-{{ $code->id }}">
                    <td class="font-mono">{{ $code->code }}</td>
                    <td>{{ $code->affiliate->display_name }}</td>
                    <td>{{ ucfirst($code->target_type->value) }}{{ $code->target_id ? ' #'.$code->target_id : '' }}</td>
                    <td><x-ui.badge variant="{{ $code->is_active ? 'success' : 'ghost' }}">{{ $code->is_active ? 'Active' : 'Inactive' }}</x-ui.badge></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
