<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Contracts\BlockTypeRegistryInterface;
use Modules\Cms\Exceptions\HtmlBlockNotPermittedException;
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

    public string $newBlockType = 'rich_text';

    public string $newBlockConfigJson = '{}';

    public ?string $blockError = null;

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

    public function addBlock(PageBuilderService $service, BlockTypeRegistryInterface $registry): void
    {
        $this->authorizeManage();
        $this->blockError = null;

        if (! $registry->has($this->newBlockType)) {
            $this->blockError = 'That block type is not available.';

            return;
        }

        $config = json_decode($this->newBlockConfigJson, associative: true);
        if (! is_array($config)) {
            $this->blockError = 'Block config must be valid JSON.';

            return;
        }

        /** @var User $editor */
        $editor = auth()->user();

        try {
            $service->addBlock($this->page, $this->newBlockType, $config, $editor, canUseHtmlBlock: (bool) auth()->user()?->can('cms.page.use_html_block'));
            $this->newBlockConfigJson = '{}';
            session()->flash('success', 'Block added.');
        } catch (HtmlBlockNotPermittedException $e) {
            $this->blockError = $e->getMessage();
        }
    }

    public function removeBlock(int $blockId, PageBuilderService $service): void
    {
        $this->authorizeManage();
        $service->removeBlock($this->page, $blockId);
    }

    public function moveBlockUp(int $blockId, PageBuilderService $service): void
    {
        $this->authorizeManage();
        $this->swapBlockPosition($blockId, -1, $service);
    }

    public function moveBlockDown(int $blockId, PageBuilderService $service): void
    {
        $this->authorizeManage();
        $this->swapBlockPosition($blockId, 1, $service);
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        return view('livewire.control-center.cms.page-editor', [
            'blocks' => $this->page->blocks()->orderBy('position')->get(),
            'availableBlockTypes' => app(BlockTypeRegistryInterface::class)->all(),
        ])->layout('layouts.control-center', ['title' => 'Edit Page']);
    }

    private function swapBlockPosition(int $blockId, int $direction, PageBuilderService $service): void
    {
        $ordered = array_values(array_map('intval', $this->page->blocks()->orderBy('position')->pluck('id')->all()));
        $index = array_search($blockId, $ordered, true);

        if ($index === false) {
            return;
        }

        $swapWith = $index + $direction;
        if (! isset($ordered[$swapWith])) {
            return;
        }

        [$ordered[$index], $ordered[$swapWith]] = [$ordered[$swapWith], $ordered[$index]];

        $service->reorderBlocks($this->page, array_values($ordered));
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('cms.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
