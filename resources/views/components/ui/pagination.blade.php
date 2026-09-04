@props(['paginator' => null])

@if($paginator && $paginator->hasPages())
    <div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-4 py-3']) }}>
        <span class="text-sm text-base-content/60">
            {{ __('Showing') }} {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} {{ __('of') }} {{ $paginator->total() }}
        </span>
        <div class="join">
            <button type="button" wire:click="previousPage" @if($paginator->onFirstPage()) disabled @endif class="join-item btn btn-sm">«</button>
            <span class="join-item btn btn-sm btn-disabled">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            <button type="button" wire:click="nextPage" @if(! $paginator->hasMorePages()) disabled @endif class="join-item btn btn-sm">»</button>
        </div>
    </div>
@endif
