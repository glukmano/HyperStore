<?php

declare(strict_types=1);

namespace Modules\Affiliate\Contracts;

use Modules\Affiliate\Models\AffiliateAttribution;

/**
 * Rule-based only (Owner Delta explicitly forbids ML/AI here). Flags are
 * always non-blocking — a flagged conversion still completes; a human
 * reviews the flag in Control Center.
 */
interface AffiliateFraudDetectionServiceInterface
{
    public function evaluateAttribution(AffiliateAttribution $attribution): void;
}
