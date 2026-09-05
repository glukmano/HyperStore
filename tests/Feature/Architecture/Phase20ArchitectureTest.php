<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Phase-20 Owner Delta architecture guarantees — grep-based structural
 * tests proving the required invariants hold codebase-wide, not just for
 * any one request/fixture.
 */
class Phase20ArchitectureTest extends TestCase
{
    /**
     * B2B/Auctions/Booking must integrate through the EXISTING commerce core
     * — never define a second Checkout/Order/Payment/Pricing engine.
     */
    public function test_no_second_checkout_order_payment_or_pricing_engine_exists(): void
    {
        $forbiddenClassPatterns = [
            '/\bclass\s+CheckoutOrchestrator\b/',
            '/\bclass\s+OrderCreationService\b/',
            '/\bclass\s+PaymentInitiationService\b/',
            '/\bclass\s+PriceResolver\b/',
        ];

        $hits = [];
        foreach ($this->phpFiles(['modules/B2B', 'modules/Auctions', 'modules/Booking']) as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbiddenClassPatterns as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $hits[] = $file;
                }
            }
        }

        $this->assertSame([], $hits, 'Phase-20 modules must never define a parallel Checkout/Order/Payment/Pricing engine class — reuse the existing modules/Checkout, modules/Order, modules/Payment, modules/Pricing services.');
    }

    /**
     * Owner Delta §5: Bid rows are genuinely append-only — no code path may
     * ever UPDATE or DELETE a bids row. The winner is derived exclusively
     * from Auction.current_bid_id, never by mutating historical Bid rows.
     */
    public function test_bid_rows_are_never_updated_or_deleted_anywhere_in_the_codebase(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules']) as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match('/\$bid(?!ding)\w*\s*->\s*(update|delete|save)\s*\(/i', $contents)) {
                $hits[] = $file.' (Eloquent mutation call against a $bid-named variable)';
            }
            if (preg_match('/Bid::where\([^)]*\)\s*->\s*(update|delete)\s*\(/', $contents)) {
                $hits[] = $file.' (bulk update/delete against the Bid model)';
            }
            if (preg_match('/DB::table\([\'"]bids[\'"]\)\s*->\s*(update|delete)\s*\(/', $contents)) {
                $hits[] = $file.' (raw query-builder mutation against the bids table)';
            }
        }

        $this->assertSame([], $hits, 'No code path may UPDATE or DELETE a bids row — Bid rows are append-only; the Auction.current_bid_id pointer is the sole winner-derivation mechanism.');
    }

    /**
     * Owner Delta §1: Company membership has exactly one source of truth
     * (company_users) — no duplicate customer_profiles.company_id column
     * or reference anywhere.
     */
    public function test_customer_profile_never_duplicates_company_membership(): void
    {
        $customerProfileMigrations = array_filter(
            glob(base_path('database/migrations/*.php')) ?: [],
            fn (string $file): bool => str_contains(strtolower(basename($file)), 'customer_profile')
        );

        foreach ($customerProfileMigrations as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('company_id', $contents, "customer_profiles must never gain a company_id column — CompanyUser is the sole membership source of truth ({$file}).");
        }

        $customerProfileModel = base_path('modules/Customers/Models/CustomerProfile.php');
        if (file_exists($customerProfileModel)) {
            $this->assertStringNotContainsString('company_id', (string) file_get_contents($customerProfileModel));
        }
    }

    /**
     * Owner Delta §2: Company-internal roles (owner/buyer/approver) must
     * never be represented by a global spatie/laravel-permission grant —
     * only spatie's platform/Control-Center staff permissions (b2b.*) may
     * appear in modules/B2B, and only for Control-Center authorization.
     */
    public function test_company_internal_roles_never_use_global_spatie_permissions(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules/B2B/Services', 'modules/B2B/Models']) as $file) {
            $contents = (string) file_get_contents($file);
            if (str_contains(basename($file), 'CompanyAuthorizationService')) {
                continue;
            }
            if (preg_match('/->hasRole\(|->assignRole\(|HasRoles\b/', $contents)) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'Company-internal roles (owner/buyer/approver) must be resolved strictly from the requesting CompanyUser row scoped to the target Company — never from a global spatie/laravel-permission grant.');
    }

    /**
     * No floating-point money: every new *_minor amount column/property in
     * Phase-20 code is an integer, and no `float`-typed money property
     * exists outside the established UI-boundary decimal-string-to-minor
     * conversion idiom (`(int) round(((float) $input) * 100)`), which itself
     * never stores the float.
     */
    public function test_no_floating_point_money_storage_in_phase_20_modules(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules/B2B/Models', 'modules/Auctions/Models', 'modules/Booking/Models']) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/[\'"]\w*(amount|price|credit|limit)\w*[\'"]\s*=>\s*[\'"]float[\'"]/i', $contents)
                || preg_match('/(float|double)\s+\$\w*(amount|price|minor|credit|limit)\w*/i', $contents)) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'Money must always be stored as integer minor units, never a float/double column or property.');
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
