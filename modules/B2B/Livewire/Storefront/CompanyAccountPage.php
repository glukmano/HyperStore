<?php

declare(strict_types=1);

namespace Modules\B2B\Livewire\Storefront;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\B2B\Models\CompanyUser;

class CompanyAccountPage extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        $companyUser = CompanyUser::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['company', 'company.companyUsers.user'])
            ->first();

        return view('b2b::livewire.storefront.company-account-page', [
            'companyUser' => $companyUser,
            'company' => $companyUser?->company,
        ])->layout('theme::layouts.app', ['title' => __('Company Account')]);
    }
}
