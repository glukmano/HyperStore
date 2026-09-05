<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Reviews\Models\ProductQuestion;
use Modules\Reviews\Services\ProductQaService;

class ProductQaSection extends Component
{
    public int $productId;

    public string $question = '';

    public function mount(int $productId): void
    {
        $this->productId = $productId;
    }

    public function ask(ProductQaService $service): void
    {
        if (! auth()->check()) {
            session()->flash('error', __('Please sign in to ask a question.'));
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $this->validate(['question' => 'required|string|min:5|max:1000']);

        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();

        /** @var User $user */
        $user = auth()->user();

        $service->ask($tenantId, $user, $this->productId, $this->question);
        $this->reset('question');
        session()->flash('success', __('Your question has been submitted for review.'));
    }

    public function render(): View
    {
        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();

        $questions = ProductQuestion::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $this->productId)
            ->where('status', ProductQuestion::STATUS_APPROVED)
            ->with(['user', 'answers' => fn ($q) => $q->where('status', 'approved')->with('user')])
            ->latest()
            ->get();

        return view('theme::components.product-qa-section', ['questions' => $questions]);
    }
}
