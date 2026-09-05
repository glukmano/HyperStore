<?php

declare(strict_types=1);

namespace Modules\Reviews\Livewire;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Reviews\Models\VendorReview;
use Modules\Reviews\Services\VendorReviewService;

class VendorReviewModerationManager extends Component
{
    public string $statusFilter = VendorReview::STATUS_PENDING;

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
    }

    public function approve(int $reviewId, VendorReviewService $service): void
    {
        $this->authorizeModerate();
        /** @var User $moderator */
        $moderator = auth()->user();
        $review = VendorReview::query()->where('tenant_id', $this->tenantId())->findOrFail($reviewId);
        $service->moderate($review, VendorReview::STATUS_APPROVED, $moderator);
        session()->flash('success', 'Review approved.');
    }

    public function reject(int $reviewId, VendorReviewService $service): void
    {
        $this->authorizeModerate();
        /** @var User $moderator */
        $moderator = auth()->user();
        $review = VendorReview::query()->where('tenant_id', $this->tenantId())->findOrFail($reviewId);
        $service->moderate($review, VendorReview::STATUS_REJECTED, $moderator, 'Rejected by moderator.');
        session()->flash('success', 'Review rejected.');
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('reviews.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $reviews = VendorReview::query()
            ->where('tenant_id', $this->tenantId())
            ->where('status', $this->statusFilter)
            ->with(['vendor', 'user'])
            ->latest()
            ->paginate(20);

        return view('livewire.control-center.reviews.vendor-review-moderation-manager', ['reviews' => $reviews])
            ->layout('layouts.control-center', ['title' => 'Vendor Review Moderation']);
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
