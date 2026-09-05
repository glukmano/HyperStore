<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Exceptions\InvalidRedirectException;
use Modules\Cms\Models\Redirect;
use Modules\Cms\Services\RedirectService;

class RedirectManager extends Component
{
    public string $fromPath = '';

    public string $toPath = '';

    public int $statusCode = 301;

    public bool $isExternal = false;

    public ?string $error = null;

    public function create(RedirectService $service): void
    {
        $this->authorizeManage();
        $this->error = null;

        $this->validate([
            'fromPath' => 'required|string|max:255',
            'toPath' => 'required|string|max:255',
            'statusCode' => 'required|in:301,302',
        ]);

        try {
            $service->create($this->tenantId(), $this->fromPath, $this->toPath, $this->statusCode, null, $this->isExternal);
            $this->reset(['fromPath', 'toPath', 'isExternal']);
            $this->statusCode = 301;
            session()->flash('success', 'Redirect created.');
        } catch (InvalidRedirectException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function toggleActive(int $redirectId): void
    {
        $this->authorizeManage();
        $redirect = Redirect::query()->where('tenant_id', $this->tenantId())->findOrFail($redirectId);
        $redirect->is_active = ! $redirect->is_active;
        $redirect->save();
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        $redirects = Redirect::query()->where('tenant_id', $this->tenantId())->latest()->paginate(20);

        return view('livewire.control-center.cms.redirect-manager', ['redirects' => $redirects])
            ->layout('layouts.control-center', ['title' => 'Redirects']);
    }

    private function tenantId(): int
    {
        return (int) app(ContextManager::class)->getTenant()->getId();
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('cms.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
