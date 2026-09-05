<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Search Merchandising') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Search Merchandising' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.table :headers="[__('Query Term'), __('Product'), __('Pin Position'), __('Active'), '']" :empty="$rules->isEmpty()" emptyMessage="{{ __('No merchandising rules yet.') }}">
            @foreach ($rules as $rule)
                <tr wire:key="rule-{{ $rule->id }}">
                    <td>{{ $rule->query_term }}</td>
                    <td>{{ $rule->product?->name }}</td>
                    <td>{{ $rule->pin_position }}</td>
                    <td>
                        <x-ui.badge variant="{{ $rule->is_active ? 'success' : 'neutral' }}">
                            {{ $rule->is_active ? __('Active') : __('Inactive') }}
                        </x-ui.badge>
                    </td>
                    <td class="text-end">
                        <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $rule->id }})">
                            {{ $rule->is_active ? __('Deactivate') : __('Activate') }}
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$rules" />
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('Pin a Product to a Query') }}</h2>
        <p class="text-sm text-base-content/60 mb-3">{{ __('A pinned product still must pass every tenant/store/publish eligibility check at query time — pinning never bypasses that.') }}</p>
        @if($error)
            <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
        @endif
        <form wire:submit="create" class="flex items-end gap-2 flex-wrap">
            <x-ui.input wire:model="queryTerm" label="{{ __('Query Term') }}" />
            <x-ui.input wire:model="sku" label="{{ __('Product SKU') }}" />
            <x-ui.input type="number" min="1" wire:model="pinPosition" label="{{ __('Pin Position') }}" />
            <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
