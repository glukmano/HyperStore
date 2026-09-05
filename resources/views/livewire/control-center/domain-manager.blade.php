<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-base-content">Domains</h1>
    </div>

    @if (session()->has('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif
    @if (session()->has('error'))
        <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.card title="Add Regional (Market) Domain">
        <form wire:submit="createMarketDomain" class="grid gap-4 sm:grid-cols-2">
            <x-ui.select wire:model="store_market_id" label="Store × Market" error="{{ $errors->first('store_market_id') }}">
                <option value="">Select…</option>
                @foreach($storeMarkets as $storeMarket)
                    <option value="{{ $storeMarket->id }}">{{ $storeMarket->store->name }} × {{ $storeMarket->market->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input wire:model="domain" label="Hostname" placeholder="e.g. de.example.com" error="{{ $errors->first('domain') }}" />
            <div class="sm:col-span-2">
                <x-ui.button type="submit" variant="primary">Add Domain</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card title="Regional (Market) Domains">
        <x-ui.table :headers="['Hostname', 'Store', 'Market', 'Verified', 'Canonical', '']" :empty="$marketDomains->isEmpty()" emptyMessage="No regional domains yet.">
            @foreach ($marketDomains as $marketDomain)
                <tr wire:key="market-domain-{{ $marketDomain->id }}">
                    <td>{{ $marketDomain->domain }}</td>
                    <td>{{ $marketDomain->storeMarket->store->name }}</td>
                    <td>{{ $marketDomain->storeMarket->market->name }}</td>
                    <td><x-ui.badge variant="{{ $marketDomain->is_verified ? 'success' : 'ghost' }}">{{ $marketDomain->is_verified ? 'Verified' : 'Unverified' }}</x-ui.badge></td>
                    <td>@if($marketDomain->canonical)<x-ui.badge variant="primary">Canonical</x-ui.badge>@endif</td>
                    <td class="space-x-2">
                        @if(! $marketDomain->is_verified)
                            <x-ui.button wire:click="verifyDomain({{ $marketDomain->id }})" variant="ghost" size="sm">Verify</x-ui.button>
                        @elseif(! $marketDomain->canonical)
                            <x-ui.button wire:click="setCanonical({{ $marketDomain->id }})" variant="ghost" size="sm">Make Canonical</x-ui.button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Store Domains (read-only)">
        <x-ui.table :headers="['Hostname', 'Store', 'Verified', 'Canonical']" :empty="$storeDomains->isEmpty()" emptyMessage="No store domains yet.">
            @foreach ($storeDomains as $storeDomain)
                <tr wire:key="store-domain-{{ $storeDomain->id }}">
                    <td>{{ $storeDomain->domain }}</td>
                    <td>{{ $storeDomain->store->name }}</td>
                    <td><x-ui.badge variant="{{ $storeDomain->is_verified ? 'success' : 'ghost' }}">{{ $storeDomain->is_verified ? 'Verified' : 'Unverified' }}</x-ui.badge></td>
                    <td>@if($storeDomain->canonical)<x-ui.badge variant="primary">Canonical</x-ui.badge>@endif</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
