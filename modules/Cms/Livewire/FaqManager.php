<?php

declare(strict_types=1);

namespace Modules\Cms\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cms\Models\Faq;
use Modules\Cms\Services\FaqService;

class FaqManager extends Component
{
    public string $question = '';

    public string $answer = '';

    public ?string $category = null;

    public function create(FaqService $service): void
    {
        $this->authorizeManage();

        $this->validate([
            'question' => 'required|string|max:255',
            'answer' => 'required|string',
        ]);

        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();
        $faq = $service->create($tenantId, $this->category ?: null);
        // Phase-18 §3: active-Locale-driven, never hardcoded.
        $service->setTranslation($faq, app()->getLocale(), $this->question, $this->answer);

        $this->reset(['question', 'answer', 'category']);
        session()->flash('success', 'FAQ entry created.');
    }

    public function togglePublished(int $faqId): void
    {
        $this->authorizeManage();
        $faq = Faq::query()->where('tenant_id', $this->tenantId())->findOrFail($faqId);
        $faq->is_published = ! $faq->is_published;
        $faq->save();
    }

    public function render(): View|Factory
    {
        $this->authorizeManage();

        $faqs = Faq::query()->where('tenant_id', $this->tenantId())->with('translations')->orderBy('sort_order')->paginate(20);

        return view('livewire.control-center.cms.faq-manager', ['faqs' => $faqs])
            ->layout('layouts.control-center', ['title' => 'FAQ']);
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
