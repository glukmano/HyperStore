<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Reviews\Events\VendorReviewApproved;
use Modules\Reviews\Events\VendorReviewRetracted;
use Modules\Reviews\Exceptions\ReviewAlreadySubmittedException;
use Modules\Reviews\Models\VendorReview;

final class VendorReviewService
{
    public function __construct(
        private readonly VerifiedPurchaseResolver $verifiedPurchaseResolver,
        private readonly AuditManagerInterface $auditManager,
    ) {}

    public function submit(int $tenantId, User $user, int $vendorId, int $rating, string $body, ?string $title = null, ?int $communicationRating = null, ?int $shippingRating = null, ?int $orderId = null): VendorReview
    {
        if (VendorReview::query()->where('tenant_id', $tenantId)->where('vendor_id', $vendorId)->where('user_id', $user->id)->exists()) {
            throw new ReviewAlreadySubmittedException('You have already reviewed this vendor.');
        }

        $isVerified = $this->verifiedPurchaseResolver->isVerifiedForVendor($tenantId, $user->id, $vendorId);

        $review = VendorReview::query()->create([
            'tenant_id' => $tenantId,
            'vendor_id' => $vendorId,
            'user_id' => $user->id,
            'order_id' => $orderId,
            'rating' => $rating,
            'communication_rating' => $communicationRating,
            'shipping_rating' => $shippingRating,
            'title' => $title,
            'body' => $body,
            'is_verified_purchase' => $isVerified,
            'status' => VendorReview::STATUS_PENDING,
        ]);

        $this->auditManager->log(event: 'review.vendor.submitted', properties: ['vendor_id' => $vendorId, 'rating' => $rating], subject: $review, causer: $user);

        return $review;
    }

    public function moderate(VendorReview $review, string $newStatus, User $moderator, ?string $reason = null): VendorReview
    {
        return DB::transaction(function () use ($review, $newStatus, $moderator, $reason): VendorReview {
            $wasApproved = $review->isApproved();

            $review->status = $newStatus;
            $review->moderated_by_user_id = $moderator->id;
            $review->moderated_at = now();
            $review->moderation_reason = $reason;
            $review->save();

            $this->auditManager->log(event: 'review.vendor.moderated', properties: ['status' => $newStatus, 'reason' => $reason], subject: $review, causer: $moderator);

            $nowApproved = $review->isApproved();

            if (! $wasApproved && $nowApproved) {
                VendorReviewApproved::dispatch($review->tenant_id, $review->vendor_id);
            } elseif ($wasApproved && ! $nowApproved) {
                VendorReviewRetracted::dispatch($review->tenant_id, $review->vendor_id);
            }

            return $review;
        });
    }
}
