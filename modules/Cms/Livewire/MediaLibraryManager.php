<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Models\Banner;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Scoped to CMS-owned media (Banner images today; the same query shape
 * extends to future CMS media-bearing models) — reuses MediaLibrary
 * directly rather than a second upload/storage system. A fully universal
 * cross-module media browser is out of scope for this pass since
 * Spatie\MediaLibrary's `media` table has no tenant_id column of its own;
 * tenant scoping here is via the owning Banner's tenant_id.
 */
class MediaLibraryManager extends Component
{
    public function delete(int $mediaId): void
    {
        $this->authorizeManage();

        $media = Media::query()->findOrFail($mediaId);
        $bannerIds = Banner::query()->where('tenant_id', $this->tenantId())->pluck('id');

        abort_unless($media->model_type === Banner::class && $bannerIds->contains($media->model_id), 403);

        $media->delete();
        session()->flash('success', 'Media file deleted.');
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        $bannerIds = Banner::query()->where('tenant_id', $this->tenantId())->pluck('id');

        $media = Media::query()
            ->where('model_type', Banner::class)
            ->whereIn('model_id', $bannerIds)
            ->latest()
            ->paginate(24);

        return view('livewire.control-center.cms.media-library-manager', ['media' => $media])
            ->layout('layouts.control-center', ['title' => 'Media Library']);
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
