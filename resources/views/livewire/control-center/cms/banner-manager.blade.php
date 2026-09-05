<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('Banners') }}</h1>
    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Banners' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.card>
        <x-ui.table :headers="[__('Placement'), __('Headline'), __('Active'), '']" :empty="$banners->isEmpty()" emptyMessage="{{ __('No banners yet.') }}">
            @foreach ($banners as $banner)
                <tr wire:key="banner-{{ $banner->id }}">
                    <td>{{ $banner->placement }}</td>
                    <td>{{ $banner->translations->firstWhere('locale', 'en')?->headline ?? $banner->translations->first()?->headline }}</td>
                    <td>
                        <x-ui.badge variant="{{ $banner->is_active ? 'success' : 'neutral' }}">
                            {{ $banner->is_active ? __('Active') : __('Inactive') }}
                        </x-ui.badge>
                    </td>
                    <td class="text-end">
                        <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $banner->id }})">
                            {{ $banner->is_active ? __('Deactivate') : __('Activate') }}
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$banners" />
    </x-ui.card>

    <x-ui.card>
        <h2 class="font-semibold mb-3">{{ __('New Banner') }}</h2>
        <form wire:submit="create" class="space-y-3 max-w-2xl">
            <x-ui.input wire:model="placement" label="{{ __('Placement') }}" />
            <x-ui.input wire:model="headline" label="{{ __('Headline') }}" />
            <x-ui.input wire:model="ctaText" label="{{ __('CTA Text (optional)') }}" />
            <x-ui.input wire:model="linkUrl" label="{{ __('Link URL (optional)') }}" />
            <div class="form-control">
                <label class="label"><span class="label-text">{{ __('Image') }}</span></label>
                <input type="file" wire:model="image" class="file-input file-input-bordered w-full" />
            </div>
            <x-ui.button type="submit" variant="primary">{{ __('Create Banner') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
