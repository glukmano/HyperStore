<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Reviews\Events\ProductReviewApproved;
use Modules\Reviews\Events\ProductReviewRetracted;
use Modules\Reviews\Exceptions\ReviewAlreadySubmittedException;
use Modules\Reviews\Models\ProductReview;

final class ProductReviewService
{
    public function __construct(
        private readonly VerifiedPurchaseResolver $verifiedPurchaseResolver,
        private readonly AuditManagerInterface $auditManager,
    ) {}

    public function submit(int $tenantId, User $user, int $productId, int $rating, string $body, ?string $title = null, ?int $orderItemId = null): ProductReview
    {
        if (ProductReview::query()->where('tenant_id', $tenantId)->where('product_id', $productId)->where('user_id', $user->id)->exists()) {
            throw new ReviewAlreadySubmittedException('You have already reviewed this product.');
        }

        $isVerified = $this->verifiedPurchaseResolver->isVerifiedForProduct($tenantId, $user->id, $productId);

        $review = ProductReview::query()->create([
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'user_id' => $user->id,
            'order_item_id' => $orderItemId,
            'rating' => $rating,
            'title' => $title,
            'body' => $body,
            'is_verified_purchase' => $isVerified,
            'status' => ProductReview::STATUS_PENDING,
        ]);

        $this->auditManager->log(event: 'review.product.submitted', properties: ['product_id' => $productId, 'rating' => $rating], subject: $review, causer: $user);

        return $review;
    }

    public function moderate(ProductReview $review, string $newStatus, User $moderator, ?string $reason = null): ProductReview
    {
        return DB::transaction(function () use ($review, $newStatus, $moderator, $reason): ProductReview {
            $wasApproved = $review->isApproved();

            $review->status = $newStatus;
            $review->moderated_by_user_id = $moderator->id;
            $review->moderated_at = now();
            $review->moderation_reason = $reason;
            $review->save();

            $this->auditManager->log(event: 'review.product.moderated', properties: ['status' => $newStatus, 'reason' => $reason], subject: $review, causer: $moderator);

            $nowApproved = $review->isApproved();

            if (! $wasApproved && $nowApproved) {
                ProductReviewApproved::dispatch($review->tenant_id, $review->product_id);
            } elseif ($wasApproved && ! $nowApproved) {
                ProductReviewRetracted::dispatch($review->tenant_id, $review->product_id);
            }

            return $review;
        });
    }
}
