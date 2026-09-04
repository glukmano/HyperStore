<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Models\Page;
use Modules\Cms\Services\PageBuilderService;

class PageManager extends Component
{
    public function create(PageBuilderService $service): void
    {
        if (! auth()->user()?->can('cms.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();
        /** @var User $author */
        $author = auth()->user();
        $page = $service->create($tenantId, $author);

        $this->redirect(route('control-center.platform.cms.pages.edit', ['page' => $page->id]), navigate: true);
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('cms.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();

        $pages = Page::query()->where('tenant_id', $tenantId)->with('translations')->latest()->paginate(20);

        return view('livewire.control-center.cms.page-manager', ['pages' => $pages])
            ->layout('layouts.control-center', ['title' => 'CMS Pages']);
    }
}
