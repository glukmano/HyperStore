<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Cms\Models\Banner;

class BannerManager extends Component
{
    use WithFileUploads;

    public string $placement = 'homepage_hero';

    public string $headline = '';

    public ?string $ctaText = null;

    public ?string $linkUrl = null;

    /** @var UploadedFile|null */
    public $image = null;

    public function create(): void
    {
        $this->authorizeManage();

        $this->validate([
            'placement' => 'required|string|max:50',
            'headline' => 'required|string|max:150',
            'image' => 'nullable|image|max:5120',
        ]);

        $banner = Banner::query()->create([
            'tenant_id' => $this->tenantId(),
            'placement' => $this->placement,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $banner->translations()->create([
            'locale' => 'en',
            'headline' => $this->headline,
            'cta_text' => $this->ctaText,
            'link_url' => $this->linkUrl,
        ]);

        if ($this->image !== null) {
            $banner->addMedia($this->image->getRealPath())
                ->usingFileName($this->image->getClientOriginalName())
                ->toMediaCollection('image');
        }

        $this->reset(['headline', 'ctaText', 'linkUrl', 'image']);
        session()->flash('success', 'Banner created.');
    }

    public function toggleActive(int $bannerId): void
    {
        $this->authorizeManage();
        $banner = Banner::query()->where('tenant_id', $this->tenantId())->findOrFail($bannerId);
        $banner->is_active = ! $banner->is_active;
        $banner->save();
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        $banners = Banner::query()->where('tenant_id', $this->tenantId())->with('translations')->orderBy('sort_order')->paginate(20);

        return view('livewire.control-center.cms.banner-manager', ['banners' => $banners])
            ->layout('layouts.control-center', ['title' => 'Banners']);
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
