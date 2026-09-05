<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Exceptions\ReservedSlugException;
use Modules\Cms\Models\BlogPost;
use Modules\Cms\Services\BlogService;

class BlogManager extends Component
{
    public string $title = '';

    public string $slug = '';

    public string $excerpt = '';

    public string $body = '';

    public ?string $slugError = null;

    public function create(BlogService $service): void
    {
        $this->authorizeManage();

        $this->validate([
            'title' => 'required|string|max:150',
            'slug' => 'required|string|max:150',
            'body' => 'required|string',
        ]);

        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();
        /** @var User $author */
        $author = auth()->user();

        $post = $service->create($tenantId, $author);

        try {
            // Phase-18 §3: driven by the editor's own active Control Center
            // Locale (switched via the existing ?lang= toggle) — never
            // hardcoded — so a newly-added active Locale is editable here
            // with zero code change.
            $service->setTranslation($post, app()->getLocale(), $this->title, $this->slug, $this->body, $this->excerpt ?: null);
            $this->reset(['title', 'slug', 'excerpt', 'body']);
            session()->flash('success', 'Blog post created.');
        } catch (ReservedSlugException $e) {
            $post->delete();
            $this->slugError = $e->getMessage();
        }
    }

    public function publish(int $postId, BlogService $service): void
    {
        $this->authorizeManage();
        $post = BlogPost::query()->where('tenant_id', $this->tenantId())->findOrFail($postId);
        $service->publish($post);
        session()->flash('success', 'Blog post published.');
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        $posts = BlogPost::query()->where('tenant_id', $this->tenantId())->with('translations')->latest()->paginate(20);

        return view('livewire.control-center.cms.blog-manager', ['posts' => $posts])
            ->layout('layouts.control-center', ['title' => 'Blog']);
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
