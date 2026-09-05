<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Reviews\Exceptions\ReviewAlreadySubmittedException;
use Modules\Reviews\Models\VendorReview;
use Modules\Reviews\Services\VendorReviewService;

class VendorReviewsSection extends Component
{
    public int $vendorId;

    public int $rating = 5;

    public string $title = '';

    public string $body = '';

    public function mount(int $vendorId): void
    {
        $this->vendorId = $vendorId;
    }

    public function submit(VendorReviewService $service): void
    {
        if (! auth()->check()) {
            session()->flash('error', __('Please sign in to write a review.'));
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $this->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:150',
            'body' => 'required|string|min:10|max:5000',
        ]);

        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();

        /** @var User $user */
        $user = auth()->user();

        try {
            $service->submit($tenantId, $user, $this->vendorId, $this->rating, $this->body, $this->title ?: null);
            $this->reset(['title', 'body']);
            $this->rating = 5;
            session()->flash('success', __('Thanks — your review is pending moderation.'));
        } catch (ReviewAlreadySubmittedException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();

        $reviews = VendorReview::query()
            ->where('tenant_id', $tenantId)
            ->where('vendor_id', $this->vendorId)
            ->where('status', VendorReview::STATUS_APPROVED)
            ->with('user', 'replies')
            ->latest()
            ->get();

        $userHasReviewed = auth()->check()
            ? VendorReview::query()->where('tenant_id', $tenantId)->where('vendor_id', $this->vendorId)->where('user_id', auth()->id())->exists()
            : false;

        return view('theme::components.vendor-reviews-section', [
            'reviews' => $reviews,
            'userHasReviewed' => $userHasReviewed,
        ]);
    }
}
