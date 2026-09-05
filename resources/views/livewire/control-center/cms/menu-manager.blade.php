<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Menus') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Menus' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <div class="flex items-end gap-2 mb-4">
            <x-ui.input wire:model.live="menuKey" label="{{ __('Menu Key') }}" />
        </div>

        <x-ui.table :headers="[__('Label'), __('Route Type'), __('Target')]" :empty="$menu->allItems->isEmpty()" emptyMessage="{{ __('No items in this menu yet.') }}">
            @foreach ($menu->allItems as $item)
                <tr wire:key="menu-item-{{ $item->id }}">
                    <td>{{ $item->label('en') }}</td>
                    <td>{{ $item->route_type }}</td>
                    <td>{{ $item->route_target }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('Add Item') }}</h2>
        <form wire:submit="addItem" class="flex items-end gap-2 flex-wrap">
            <x-ui.input wire:model="label" label="{{ __('Label') }}" />
            <div class="form-control">
                <label class="label"><span class="label-text">{{ __('Route Type') }}</span></label>
                <select wire:model="routeType" class="select select-bordered">
                    <option value="page">{{ __('Page') }}</option>
                    <option value="category">{{ __('Category') }}</option>
                    <option value="product">{{ __('Product') }}</option>
                    <option value="vendor">{{ __('Vendor') }}</option>
                    <option value="external">{{ __('External') }}</option>
                </select>
            </div>
            <x-ui.input wire:model="routeTarget" label="{{ __('Target (slug or URL)') }}" />
            <x-ui.button type="submit" variant="primary">{{ __('Add') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
