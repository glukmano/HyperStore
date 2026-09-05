<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Reviews\Contracts\RatingAggregateReaderInterface;
use Modules\Reviews\Exceptions\ReviewAlreadySubmittedException;
use Modules\Reviews\Models\ProductReview;
use Modules\Reviews\Services\ProductReviewService;

class ProductReviewsSection extends Component
{
    public int $productId;

    public int $rating = 5;

    public string $title = '';

    public string $body = '';

    public function mount(int $productId): void
    {
        $this->productId = $productId;
    }

    public function submit(ProductReviewService $service): void
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
            $service->submit($tenantId, $user, $this->productId, $this->rating, $this->body, $this->title ?: null);
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

        $reviews = ProductReview::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $this->productId)
            ->where('status', ProductReview::STATUS_APPROVED)
            ->with('user', 'replies')
            ->latest()
            ->get();

        $aggregate = app(RatingAggregateReaderInterface::class)->forProduct($this->productId);

        $userHasReviewed = auth()->check()
            ? ProductReview::query()->where('tenant_id', $tenantId)->where('product_id', $this->productId)->where('user_id', auth()->id())->exists()
            : false;

        return view('theme::components.product-reviews-section', [
            'reviews' => $reviews,
            'aggregate' => $aggregate,
            'userHasReviewed' => $userHasReviewed,
        ]);
    }
}
