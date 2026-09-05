<x-ui.card class="mt-6">
    <h2 class="text-xl font-bold mb-4">{{ __('Vendor Reviews') }}</h2>

    <div class="space-y-4 mb-6">
        @forelse($reviews as $review)
            <div class="border-b border-base-300 pb-4">
                <div class="flex items-center gap-2">
                    <span class="font-semibold">{{ $review->user->name }}</span>
                    @if($review->is_verified_purchase)
                        <x-ui.badge variant="success">{{ __('Verified Purchase') }}</x-ui.badge>
                    @endif
                    <span>{{ str_repeat('⭐', $review->rating) }}</span>
                </div>
                @if($review->title)
                    <p class="font-medium mt-1">{{ $review->title }}</p>
                @endif
                <p class="text-base-content/80">{{ $review->body }}</p>

                @foreach($review->replies as $reply)
                    <div class="ms-6 mt-2 ps-3 border-s-2 border-base-300 text-sm">
                        <span class="font-semibold">{{ __('Seller reply') }}:</span> {{ $reply->body }}
                    </div>
                @endforeach
            </div>
        @empty
            <x-ui.empty-state message="{{ __('No vendor reviews yet.') }}" />
        @endforelse
    </div>

    @auth
        @if(! $userHasReviewed)
            <form wire:submit="submit" class="space-y-3 max-w-lg">
                <h3 class="font-semibold">{{ __('Write a review') }}</h3>
                <div class="form-control">
                    <label class="label"><span class="label-text">{{ __('Rating') }}</span></label>
                    <select wire:model="rating" class="select select-bordered">
                        @for($i = 5; $i >= 1; $i--)
                            <option value="{{ $i }}">{{ $i }} ⭐</option>
                        @endfor
                    </select>
                </div>
                <x-ui.input wire:model="title" label="{{ __('Title (optional)') }}" />
                <x-ui.textarea wire:model="body" label="{{ __('Your review') }}" rows="4" />
                <x-ui.button type="submit" variant="primary">{{ __('Submit Review') }}</x-ui.button>
            </form>
        @else
            <p class="text-base-content/60 text-sm">{{ __('You have already reviewed this vendor.') }}</p>
        @endif
    @else
        <a href="{{ route('login') }}" wire:navigate class="link">{{ __('Sign in to write a review') }}</a>
    @endauth
</x-ui.card>
