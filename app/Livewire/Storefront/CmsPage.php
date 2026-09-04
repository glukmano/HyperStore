<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Models\Page;
use Modules\Cms\Models\PageTranslation;
use Modules\Cms\Services\PageBlockRenderer;

class CmsPage extends Component
{
    public string $slug;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    public function render(PageBlockRenderer $renderer, LocaleManagerInterface $localeManager): View
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        $locale = $localeManager->getLocale();

        $translation = PageTranslation::query()
            ->where('slug', $this->slug)
            ->where('locale', $locale)
            ->whereHas('page', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', Page::STATUS_PUBLISHED))
            ->with('page.blocks')
            ->first();

        if ($translation === null || $translation->page === null) {
            abort(404);
        }

        $renderedBlocks = $translation->page->blocks
            ->where('is_visible', true)
            ->map(fn ($block) => $renderer->render($block));

        return view('theme::pages.cms-page', ['translation' => $translation, 'renderedBlocks' => $renderedBlocks])
            ->layout('theme::layouts.app', ['title' => $translation->meta_title ?? $translation->title]);
    }
}
