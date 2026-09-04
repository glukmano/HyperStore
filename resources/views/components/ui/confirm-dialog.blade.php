@props(['show' => false, 'title' => 'Are you sure?', 'message' => 'This action cannot be undone.', 'confirmAction' => null, 'cancelAction' => null, 'confirmLabel' => 'Confirm', 'variant' => 'danger'])

<div class="modal{{ $show ? ' modal-open' : '' }}" x-data role="alertdialog" aria-modal="true">
    <div class="modal-box">
        <h3 class="text-lg font-bold">{{ $title }}</h3>
        <p class="py-4 text-base-content/70">{{ $message }}</p>
        <div class="modal-action">
            @if($cancelAction)
                <x-ui.button variant="ghost" wire:click="{{ $cancelAction }}">Cancel</x-ui.button>
            @endif
            @if($confirmAction)
                <x-ui.button :variant="$variant" wire:click="{{ $confirmAction }}">{{ $confirmLabel }}</x-ui.button>
            @endif
        </div>
    </div>
    @if($cancelAction)
        <div class="modal-backdrop" wire:click="{{ $cancelAction }}"></div>
    @else
        <div class="modal-backdrop"></div>
    @endif
</div>
