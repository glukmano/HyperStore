<?php

declare(strict_types=1);

namespace Modules\Reviews\Livewire;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Reviews\Models\ProductReview;
use Modules\Reviews\Services\ProductReviewService;

class ReviewModerationManager extends Component
{
    public string $statusFilter = ProductReview::STATUS_PENDING;

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
    }

    public function approve(int $reviewId, ProductReviewService $service): void
    {
        $this->authorizeModerate();
        /** @var User $moderator */
        $moderator = auth()->user();
        $review = ProductReview::query()->where('tenant_id', $this->tenantId())->findOrFail($reviewId);
        $service->moderate($review, ProductReview::STATUS_APPROVED, $moderator);
        session()->flash('success', 'Review approved.');
    }

    public function reject(int $reviewId, ProductReviewService $service): void
    {
        $this->authorizeModerate();
        /** @var User $moderator */
        $moderator = auth()->user();
        $review = ProductReview::query()->where('tenant_id', $this->tenantId())->findOrFail($reviewId);
        $service->moderate($review, ProductReview::STATUS_REJECTED, $moderator, 'Rejected by moderator.');
        session()->flash('success', 'Review rejected.');
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('reviews.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $reviews = ProductReview::query()
            ->where('tenant_id', $this->tenantId())
            ->where('status', $this->statusFilter)
            ->with(['product', 'user'])
            ->latest()
            ->paginate(20);

        return view('livewire.control-center.reviews.review-moderation-manager', ['reviews' => $reviews])
            ->layout('layouts.control-center', ['title' => 'Review Moderation']);
    }

    private function tenantId(): int
    {
        return (int) app(ContextManager::class)->getTenant()->getId();
    }

    private function authorizeModerate(): void
    {
        if (! auth()->user()?->can('reviews.moderate') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
