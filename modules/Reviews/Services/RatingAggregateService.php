<?php

declare(strict_types=1);

namespace Modules\Reviews\Services;

use Modules\Reviews\Contracts\RatingAggregateReaderInterface;
use Modules\Reviews\Models\ProductRatingAggregate;
use Modules\Reviews\Models\ProductReview;
use Modules\Reviews\Models\VendorRatingAggregate;
use Modules\Reviews\Models\VendorReview;

/**
 * Deterministic, safely-recomputable rating aggregates. Recomputed
 * event-driven on every approve/retract (via listeners) and, as
 * drift-correction insurance, by a nightly full-reconciliation job — never
 * a DB trigger (Larastan/Pest can't see trigger logic) and never an
 * incrementing counter with no recompute path.
 */
final class RatingAggregateService implements RatingAggregateReaderInterface
{
    public function recomputeForProduct(int $tenantId, int $productId): void
    {
        $stats = ProductReview::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('status', ProductReview::STATUS_APPROVED)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as cnt')
            ->first();

        ProductRatingAggregate::query()->updateOrCreate(
            ['product_id' => $productId],
            [
                'tenant_id' => $tenantId,
                'average_rating' => round((float) ($stats->avg_rating ?? 0), 2),
                'review_count' => (int) ($stats->cnt ?? 0),
                'updated_at' => now(),
            ],
        );
    }

    public function recomputeForVendor(int $tenantId, int $vendorId): void
    {
        $stats = VendorReview::query()
            ->where('tenant_id', $tenantId)
            ->where('vendor_id', $vendorId)
            ->where('status', VendorReview::STATUS_APPROVED)
            ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as cnt')
            ->first();

        VendorRatingAggregate::query()->updateOrCreate(
            ['vendor_id' => $vendorId],
            [
                'tenant_id' => $tenantId,
                'average_rating' => round((float) ($stats->avg_rating ?? 0), 2),
                'review_count' => (int) ($stats->cnt ?? 0),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @return array{average: float, count: int}
     */
    public function forProduct(int $productId): array
    {
        $aggregate = ProductRatingAggregate::query()->find($productId);

        return ['average' => (float) ($aggregate->average_rating ?? 0), 'count' => $aggregate->review_count ?? 0];
    }

    /**
     * @return array{average: float, count: int}
     */
    public function forVendor(int $vendorId): array
    {
        $aggregate = VendorRatingAggregate::query()->find($vendorId);

        return ['average' => (float) ($aggregate->average_rating ?? 0), 'count' => $aggregate->review_count ?? 0];
    }

    /**
     * Recomputes every product/vendor aggregate from scratch — the nightly
     * drift-correction safety net.
     */
    public function recomputeAll(): void
    {
        ProductReview::query()
            ->select('tenant_id', 'product_id')
            ->distinct()
            ->get()
            ->each(fn (ProductReview $row) => $this->recomputeForProduct($row->tenant_id, $row->product_id));

        VendorReview::query()
            ->select('tenant_id', 'vendor_id')
            ->distinct()
            ->get()
            ->each(fn (VendorReview $row) => $this->recomputeForVendor($row->tenant_id, $row->vendor_id));
    }
}
