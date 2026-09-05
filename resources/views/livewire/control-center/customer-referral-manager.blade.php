<div class="space-y-6">
    <h1 class="text-2xl font-bold tracking-tight text-base-content">Customer Referrals</h1>

    <x-ui.card title="Referrals">
        <x-ui.table :headers="['Referrer', 'Referred', 'Status', 'Qualifying Order', 'Reward State']" :empty="$referrals->isEmpty()" emptyMessage="No referrals yet.">
            @foreach ($referrals as $referral)
                <tr wire:key="referral-{{ $referral->id }}">
                    <td>{{ $referral->referrer->user->name ?? ('#'.$referral->referrer_customer_profile_id) }}</td>
                    <td>{{ $referral->referred->user->name ?? ('#'.$referral->referred_customer_profile_id) }}</td>
                    <td>
                        <x-ui.badge variant="{{ $referral->status === 'rewarded' ? 'success' : ($referral->status === 'pending' ? 'warning' : 'ghost') }}">
                            {{ ucfirst($referral->status) }}
                        </x-ui.badge>
                    </td>
                    <td>{{ $referral->qualifyingOrder->order_number ?? '—' }}</td>
                    <td>
                        @php $rewardState = $rewardStatusByReferralId[$referral->id] ?? null; @endphp
                        {{ $rewardState !== null ? ucfirst($rewardState) : '—' }}
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</div>
