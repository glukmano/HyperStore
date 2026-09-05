<div class="flex flex-col md:flex-row gap-6">
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-4">
        <h1 class="text-2xl font-bold">{{ __('Messages') }}</h1>

        @if($conversations->isEmpty())
            <x-ui.empty-state message="{{ __('No conversations yet.') }}" />
        @else
            <x-ui.table :headers="[__('With'), __('Subject'), __('Last Activity'), '']">
                @foreach($conversations as $conversation)
                    <tr wire:key="conv-{{ $conversation->id }}">
                        <td>{{ $conversation->vendor?->name ?? __('Support') }}</td>
                        <td>{{ $conversation->subject ?? __('(no subject)') }}</td>
                        <td>{{ $conversation->last_message_at?->diffForHumans() }}</td>
                        <td class="text-end">
                            <a href="{{ route('account.messages.show', $conversation) }}" wire:navigate class="btn btn-ghost btn-sm">
                                {{ __('Open') }}
                                @if(($unreadCounts[$conversation->id] ?? 0) > 0)
                                    <x-ui.badge variant="accent">{{ $unreadCounts[$conversation->id] }}</x-ui.badge>
                                @endif
                            </a>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </div>
</div>
