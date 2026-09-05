<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold">{{ __('Vendor Review Moderation') }}</h1>
        <p class="text-sm text-base-content/60">{{ __('Approve or reject Vendor reviews awaiting moderation.') }}</p>
    </div>

    <x-ui.breadcrumbs :items="['Control Center' => route('control-center.dashboard'), 'Vendor Review Moderation' => null]" />

    @if (session('success'))
        <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
    @endif

    <x-ui.tabs :tabs="['pending' => __('Pending'), 'flagged' => __('Flagged'), 'approved' => __('Approved'), 'rejected' => __('Rejected')]" :active="$statusFilter" switchAction="setStatusFilter" />

    <x-ui.card>
        <x-ui.table :headers="[__('Vendor'), __('Reviewer'), __('Rating'), __('Body'), __('Verified'), '']" :empty="$reviews->isEmpty()" emptyMessage="{{ __('No reviews in this status.') }}">
            @foreach ($reviews as $review)
                <tr wire:key="vreview-{{ $review->id }}">
                    <td>{{ $review->vendor->name }}</td>
                    <td>{{ $review->user->name }}</td>
                    <td>{{ $review->rating }}/5</td>
                    <td class="max-w-sm truncate">{{ $review->body }}</td>
                    <td>
                        @if ($review->is_verified_purchase)
                            <x-ui.badge variant="success">{{ __('Verified') }}</x-ui.badge>
                        @endif
                    </td>
                    <td class="text-end space-x-2">
                        <x-ui.button size="sm" variant="success" wire:click="approve({{ $review->id }})">{{ __('Approve') }}</x-ui.button>
                        <x-ui.button size="sm" variant="danger" wire:click="reject({{ $review->id }})">{{ __('Reject') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
        <x-ui.pagination :paginator="$reviews" />
    </x-ui.card>
</div>
