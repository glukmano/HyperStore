<?php

declare(strict_types=1);

namespace Modules\B2B\Contracts;

use Modules\B2B\Exceptions\InsufficientCompanyCreditException;
use Modules\B2B\Models\Company;

/**
 * Owner Delta §1/§2/§3: CompanyCredit is an EXPOSURE CONTROL subledger, not
 * a customer money balance. A `reservation` increases exposure; it is
 * resolved EXACTLY ONCE by either `release` (cancelled before settlement) or
 * `settlement` (invoice paid) — never both, DB-enforced. A refund AFTER
 * settlement never touches this subledger at all.
 */
interface CompanyCreditServiceInterface
{
    /**
     * Reserves credit exposure for a not-yet-invoiced Order, atomically
     * inside the CALLER's own transaction (Owner Delta §2 — this method
     * does not open its own outer transaction; it must be invoked from
     * inside OrderCreationService's existing DB::transaction() so that an
     * insufficient-credit rejection rolls back the entire Order).
     * Idempotent by `$orderUuid`.
     *
     * @throws InsufficientCompanyCreditException
     */
    public function reserveForOrder(Company $company, int $amountMinor, string $currency, string $orderUuid): void;

    /**
     * Resolves a reservation without payment (Order cancelled before
     * invoice settlement). Idempotent — a repeat call for an
     * already-released reservation is a safe no-op.
     */
    public function releaseReservation(int $tenantId, string $orderUuid): void;

    /**
     * Resolves a reservation via invoice payment. Idempotent.
     */
    public function settleReservation(int $tenantId, string $orderUuid): void;

    public function getAvailableCreditMinor(Company $company, string $currency): int;
}
