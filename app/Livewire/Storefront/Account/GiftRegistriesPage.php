<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Models\GiftRegistry;
use Modules\Customers\Services\GiftRegistryService;

class GiftRegistriesPage extends Component
{
    public string $title = '';

    public string $eventType = 'other';

    public ?string $eventDate = null;

    public function create(GiftRegistryService $service): void
    {
        $this->validate([
            'title' => 'required|string|max:150',
            'eventType' => 'required|in:wedding,baby,birthday,other',
            'eventDate' => 'nullable|date',
        ]);

        /** @var User $user */
        $user = auth()->user();

        $registry = $service->create($user, $this->title, $this->eventType, $this->eventDate);

        $this->redirect(route('account.gift-registries.show', $registry), navigate: true);
    }

    public function render(): View
    {
        $registries = GiftRegistry::query()->where('user_id', auth()->id())->latest()->get();

        return view('theme::pages.account.gift-registries-index', ['registries' => $registries])
            ->layout('theme::layouts.app', ['title' => __('Gift Registries')]);
    }
}
