<div class="flex flex-col md:flex-row gap-6">
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-6">
        <h1 class="text-2xl font-bold">{{ __('Gift Registries') }}</h1>

        @if($registries->isEmpty())
            <x-ui.empty-state message="{{ __('You have not created any gift registries yet.') }}" />
        @else
            <x-ui.table :headers="[__('Title'), __('Event'), '']">
                @foreach($registries as $registry)
                    <tr wire:key="registry-{{ $registry->id }}">
                        <td>{{ $registry->title }}</td>
                        <td>{{ ucfirst($registry->event_type) }}</td>
                        <td class="text-end">
                            <a href="{{ route('account.gift-registries.show', $registry) }}" wire:navigate class="btn btn-ghost btn-sm">{{ __('Manage') }}</a>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif

        <x-ui.card>
            <h2 class="font-semibold mb-3">{{ __('Create a Registry') }}</h2>
            <form wire:submit="create" class="space-y-3 max-w-md">
                <x-ui.input wire:model="title" label="{{ __('Title') }}" />
                <div class="form-control">
                    <label class="label"><span class="label-text">{{ __('Event Type') }}</span></label>
                    <select wire:model="eventType" class="select select-bordered">
                        <option value="wedding">{{ __('Wedding') }}</option>
                        <option value="baby">{{ __('Baby') }}</option>
                        <option value="birthday">{{ __('Birthday') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                </div>
                <x-ui.input type="date" wire:model="eventDate" label="{{ __('Event Date (optional)') }}" />
                <x-ui.button type="submit" variant="primary">{{ __('Create Registry') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</div>
