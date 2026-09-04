<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use Illuminate\Http\UploadedFile;
use Modules\Reviews\Models\ProductReview;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Mirrors Modules\Catalog\Services\CatalogMediaService's exact shape — the
 * one established media-attachment pattern in this codebase, reused rather
 * than a bespoke upload path.
 */
class ReviewMediaService
{
    public function attachReviewPhoto(ProductReview $review, UploadedFile $file): Media
    {
        return $review->addMedia($file)->toMediaCollection('review_photos');
    }

    public function attachReviewVideo(ProductReview $review, UploadedFile $file): Media
    {
        return $review->addMedia($file)->toMediaCollection('review_videos');
    }
}
