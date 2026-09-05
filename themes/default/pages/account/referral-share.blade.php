<div class="flex flex-col md:flex-row gap-6">
    @include('theme::components.account-nav')

    <div class="flex-1 space-y-4">
        <h1 class="text-2xl font-bold">{{ __('Refer a Friend') }}</h1>

        <x-ui.card>
            <p class="text-sm text-base-content/70 mb-3">{{ __('Share your link. When a friend signs up and completes their first order, you earn Loyalty points.') }}</p>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ $shareUrl }}" class="input input-bordered w-full" onclick="this.select()" />
            </div>
            <p class="text-sm text-base-content/60 mt-2">{{ __('Referral code') }}: <span class="font-mono">{{ $code }}</span></p>
        </x-ui.card>

        <x-ui.card title="{{ __('Your Referrals') }}">
            <x-ui.table :headers="[__('Friend'), __('Status')]" :empty="$referrals->isEmpty()" emptyMessage="{{ __('No referrals yet — share your link above.') }}">
                @foreach ($referrals as $referral)
                    <tr wire:key="referral-{{ $referral->id }}">
                        <td>{{ $referral->referred->user->name ?? __('A friend') }}</td>
                        <td>
                            <x-ui.badge variant="{{ $referral->status === 'rewarded' ? 'success' : 'warning' }}">
                                {{ ucfirst($referral->status) }}
                            </x-ui.badge>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    </div>
</div>
