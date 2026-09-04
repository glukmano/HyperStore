<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\RecentlyViewedItem;

/**
 * Guest (session-scoped) and authenticated (user-scoped) product view
 * tracking. Retention is self-bounding: each write trims the identity's
 * history back down to RETENTION_LIMIT rows in the same call, rather than
 * relying on a separate scheduled sweep for the cap itself (only guest
 * expiry-by-age needs a scheduled job — see RecentlyViewedGuestPruneJob).
 */
final class RecentlyViewedService
{
    private const int RETENTION_LIMIT = 50;

    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function recordView(int $productId, ?User $user, ?string $sessionId): void
    {
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        DB::transaction(function () use ($tenantId, $productId, $user, $sessionId): void {
            $identity = $user !== null
                ? ['tenant_id' => $tenantId, 'user_id' => $user->id, 'product_id' => $productId]
                : ['tenant_id' => $tenantId, 'session_id' => $sessionId, 'product_id' => $productId];

            $existing = RecentlyViewedItem::query()->where($identity)->first();

            if ($existing !== null) {
                $existing->increment('view_count');
                $existing->viewed_at = now();
                $existing->save();
            } else {
                RecentlyViewedItem::query()->create($identity + ['viewed_at' => now(), 'view_count' => 1]);
            }

            $this->trimToRetentionLimit($tenantId, $user, $sessionId);
        });
    }

    private function trimToRetentionLimit(int $tenantId, ?User $user, ?string $sessionId): void
    {
        $query = RecentlyViewedItem::query()->where('tenant_id', $tenantId);
        $query = $user !== null ? $query->where('user_id', $user->id) : $query->where('session_id', $sessionId);

        $idsToKeep = $query->clone()->orderByDesc('viewed_at')->limit(self::RETENTION_LIMIT)->pluck('id');

        $query->clone()->whereNotIn('id', $idsToKeep)->delete();
    }
}
