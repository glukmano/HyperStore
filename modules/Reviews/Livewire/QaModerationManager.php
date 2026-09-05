<?php

declare(strict_types=1);

namespace Modules\Reviews\Livewire;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Reviews\Models\ProductAnswer;
use Modules\Reviews\Models\ProductQuestion;
use Modules\Reviews\Services\ProductQaService;

class QaModerationManager extends Component
{
    public string $statusFilter = ProductQuestion::STATUS_PENDING;

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
    }

    public function approveQuestion(int $questionId, ProductQaService $service): void
    {
        $this->authorizeModerate();
        /** @var User $moderator */
        $moderator = auth()->user();
        $question = ProductQuestion::query()->where('tenant_id', $this->tenantId())->findOrFail($questionId);
        $service->moderateQuestion($question, ProductQuestion::STATUS_APPROVED, $moderator);
        session()->flash('success', 'Question approved.');
    }

    public function rejectQuestion(int $questionId, ProductQaService $service): void
    {
        $this->authorizeModerate();
        /** @var User $moderator */
        $moderator = auth()->user();
        $question = ProductQuestion::query()->where('tenant_id', $this->tenantId())->findOrFail($questionId);
        $service->moderateQuestion($question, ProductQuestion::STATUS_REJECTED, $moderator);
        session()->flash('success', 'Question rejected.');
    }

    public function approveAnswer(int $answerId, ProductQaService $service): void
    {
        $this->authorizeModerate();
        /** @var User $moderator */
        $moderator = auth()->user();
        $answer = ProductAnswer::query()->findOrFail($answerId);
        $service->moderateAnswer($answer, ProductAnswer::STATUS_APPROVED, $moderator);
        session()->flash('success', 'Answer approved.');
    }

    public function rejectAnswer(int $answerId, ProductQaService $service): void
    {
        $this->authorizeModerate();
        /** @var User $moderator */
        $moderator = auth()->user();
        $answer = ProductAnswer::query()->findOrFail($answerId);
        $service->moderateAnswer($answer, ProductAnswer::STATUS_REJECTED, $moderator);
        session()->flash('success', 'Answer rejected.');
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('reviews.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $questions = ProductQuestion::query()
            ->where('tenant_id', $this->tenantId())
            ->where('status', $this->statusFilter)
            ->with(['product', 'user'])
            ->latest()
            ->paginate(20);

        $pendingAnswers = ProductAnswer::query()
            ->where('status', ProductAnswer::STATUS_PENDING)
            ->whereHas('question', fn ($q) => $q->where('tenant_id', $this->tenantId()))
            ->with(['question', 'user'])
            ->latest()
            ->get();

        return view('livewire.control-center.reviews.qa-moderation-manager', [
            'questions' => $questions,
            'pendingAnswers' => $pendingAnswers,
        ])->layout('layouts.control-center', ['title' => 'Q&A Moderation']);
    }

    private function tenantId(): int
    {
        return (int) app(ContextManager::class)->getTenant()->getId();
    }

    private function authorizeModerate(): void
    {
        if (! auth()->user()?->can('qa.moderate') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
