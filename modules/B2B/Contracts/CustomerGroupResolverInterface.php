<?php

declare(strict_types=1);

namespace Modules\B2B\Contracts;

/**
 * Resolves the Pricing `customer_group_id` a User's active Company
 * membership maps to (B.8/B.9) — the sole path wholesale/negotiated
 * PriceBook pricing reaches the live Checkout pricing pass. Reads
 * `CompanyUser` (the sole Company-membership source of truth, Owner Delta
 * §1) — never a denormalized column anywhere else.
 */
interface CustomerGroupResolverInterface
{
    public function resolveForUser(int $tenantId, ?int $userId): ?int;
}
