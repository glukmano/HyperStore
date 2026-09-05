<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Phase-19 Owner Delta architecture guarantees — grep-based regression tests
 * proving the structural requirements hold, not just behavioral tests of any
 * one request.
 */
class Phase19ArchitectureTest extends TestCase
{
    /**
     * Owner Delta correction §1: the payout request/allocate/finalize/
     * cancel/fail state machine must exist exactly once, shared by every
     * beneficiary type — never a second, independently hand-copied engine.
     */
    public function test_only_one_payout_orchestration_algorithm_exists(): void
    {
        $this->assertFileExists(base_path('app/Core/Payables/Services/AbstractPayoutOrchestrator.php'));

        $marketplacePayoutService = file_get_contents(base_path('modules/Marketplace/Services/PayoutService.php'));
        $affiliatePayoutService = file_get_contents(base_path('modules/Affiliate/Services/AffiliatePayoutService.php'));

        foreach ([$marketplacePayoutService, $affiliatePayoutService] as $contents) {
            $this->assertStringContainsString('AbstractPayoutOrchestrator', $contents);
            $this->assertStringNotContainsString('DB::transaction(function ()', $contents, 'Beneficiary-specific payout services must delegate to the shared orchestrator, never reimplement the transactional state machine themselves.');
        }
    }

    /**
     * Owner Delta correction §22: Loyalty points are non-cash and never a
     * modules/Ledger monetary account.
     */
    public function test_loyalty_code_never_touches_ledger(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules/Promotions']) as $file) {
            if (! str_contains($file, 'Loyalty')) {
                continue;
            }
            $contents = file_get_contents($file);
            if (str_contains($contents, 'Modules\\Ledger') || str_contains($contents, 'LedgerAccount') || str_contains($contents, 'LedgerEntry')) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'Loyalty must never reference modules/Ledger — points are non-cash, non-withdrawable, discount-entitlement only.');
    }

    /**
     * Owner Delta correction §1: domain-owned payable subledgers stay
     * separate per beneficiary type — Affiliate must never read/write
     * Marketplace's VendorPayableEntry table or vice versa.
     */
    public function test_affiliate_and_marketplace_payable_subledgers_stay_separate(): void
    {
        $this->assertNoRealCodeReference($this->phpFiles(['modules/Affiliate']), 'VendorPayableEntry');
        $this->assertNoRealCodeReference($this->phpFiles(['modules/Marketplace']), 'AffiliatePayableEntry');
    }

    /**
     * Asserts $needle never appears as an actual code reference (a `use`
     * import or `Needle::` static access) — a docblock comment merely
     * describing the analogous Marketplace/Affiliate class by name is fine.
     *
     * @param  list<string>  $files
     */
    private function assertNoRealCodeReference(array $files, string $needle): void
    {
        $hits = [];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/\buse\s+[^;]*'.preg_quote($needle, '/').'\s*;/', $contents)
                || preg_match('/\b'.preg_quote($needle, '/').'::/', $contents)) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, "Found a real code reference to {$needle} (not just a docblock comment) in: ".implode(', ', $hits));
    }

    /**
     * Owner Delta correction §3/§2: commission is computed once, from
     * immutable OrderItem snapshot fields, at Order-creation time — never
     * recomputed from live Product/Category/Vendor pricing after the fact
     * inside the activation/refund-reversal paths.
     */
    public function test_conversion_activation_and_reversal_never_recompute_from_live_pricing(): void
    {
        $files = [
            base_path('modules/Affiliate/Listeners/ActivateAffiliateConversionOnOrderPaidListener.php'),
            base_path('modules/Affiliate/Services/AffiliateConversionReversalService.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('Modules\\Catalog\\Models\\Product', $contents);
            $this->assertStringNotContainsString('AffiliateCommissionRuleResolverInterface', $contents);
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $path = base_path($dir);
            if (! is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
