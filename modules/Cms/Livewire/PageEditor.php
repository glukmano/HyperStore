<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Exceptions\ReservedSlugException;
use Modules\Cms\Models\Page;
use Modules\Cms\Services\PageBuilderService;

class PageEditor extends Component
{
    public Page $page;

    public string $locale = 'en';

    public string $title = '';

    public string $slug = '';

    public ?string $slugError = null;

    public function mount(Page $page): void
    {
        $this->authorizeManage();
        $this->page = $page;

        $translation = $page->translation($this->locale);
        $this->title = $translation->title ?? '';
        $this->slug = $translation->slug ?? '';
    }

    public function save(PageBuilderService $service): void
    {
        $this->authorizeManage();
        $this->slugError = null;

        try {
            $service->setTranslation($this->page, $this->locale, $this->title, $this->slug);
            session()->flash('success', 'Page saved.');
        } catch (ReservedSlugException $e) {
            $this->slugError = $e->getMessage();
        }
    }

    public function publish(PageBuilderService $service): void
    {
        $this->authorizeManage();
        /** @var User $editor */
        $editor = auth()->user();
        $service->publish($this->page, $editor);
        session()->flash('success', 'Page published.');
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        return view('livewire.control-center.cms.page-editor', ['blocks' => $this->page->blocks])
            ->layout('layouts.control-center', ['title' => 'Edit Page']);
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('cms.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
