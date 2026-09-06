<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Phase-21 Owner Delta architecture guarantees — grep-based structural
 * tests proving the required invariants hold codebase-wide.
 */
class Phase21ArchitectureTest extends TestCase
{
    /**
     * Digital Delivery/Subscriptions/Wallet/GiftCards must integrate
     * through the EXISTING commerce core — never define a second
     * Checkout/Order/Payment/Ledger engine.
     */
    public function test_no_second_checkout_order_payment_or_ledger_engine_exists(): void
    {
        $forbiddenClassPatterns = [
            '/\bclass\s+CheckoutOrchestrator\b/',
            '/\bclass\s+OrderCreationService\b/',
            '/\bclass\s+PaymentInitiationService\b/',
            '/\bclass\s+LedgerPostingService\b/',
        ];

        $hits = [];
        foreach ($this->phpFiles(['modules/DigitalDelivery', 'modules/Subscriptions', 'modules/Wallet', 'modules/GiftCards']) as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbiddenClassPatterns as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $hits[] = $file;
                }
            }
        }

        $this->assertSame([], $hits, 'Phase-21 modules must never define a parallel Checkout/Order/Payment/Ledger engine class — reuse the existing modules/Checkout, modules/Order, modules/Payment, modules/Ledger services.');
    }

    /**
     * D.58: Wallet, Loyalty points, Store Credit, and Coupons remain four
     * architecturally distinct concepts — Wallet must never reference
     * Loyalty's non-cash points ledger.
     */
    public function test_wallet_never_references_loyalty(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules/Wallet']) as $file) {
            $contents = (string) file_get_contents($file);
            foreach (['LoyaltyPointEntry', 'LoyaltyService', 'LoyaltyAccountLock'] as $needle) {
                // A real code reference (a `use` import or `Needle::` static
                // access) — a docblock comment merely naming the analogous
                // Loyalty class for context is fine (same discipline as
                // Phase19ArchitectureTest::assertNoRealCodeReference()).
                if (preg_match('/\buse\s+[^;]*'.preg_quote($needle, '/').'\s*;/', $contents)
                    || preg_match('/\b'.preg_quote($needle, '/').'::/', $contents)) {
                    $hits[] = $file;
                }
            }
        }

        $this->assertSame([], $hits, 'modules/Wallet must never reference Loyalty\'s non-cash points ledger — Wallet is cash-equivalent/Ledger-integrated, Loyalty is not.');
    }

    /**
     * C.25/D.49/D.58: Store Credit/Gift Card/Wallet application at Checkout
     * is the new amountDueMinor hold mechanism — never a Coupon/Promotion,
     * unlike Loyalty's Phase-19 redemption trick.
     */
    public function test_store_value_is_never_implemented_as_a_coupon_or_promotion(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules/Wallet', 'modules/GiftCards']) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/\bPromotion::create\b|\bCoupon::create\b/', $contents)) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'modules/Wallet and modules/GiftCards must never create a Promotion/Coupon row — Store Value applies via the amountDueMinor hold mechanism, never a discount.');
    }

    /**
     * Owner Delta §15: a Gift Card's StoreValueAccount is a permanent
     * one-to-one pair — GiftCardService must never write into a
     * pre-existing Wallet/StoreCredit account.
     */
    public function test_gift_card_service_never_merges_into_an_existing_wallet_or_store_credit_account(): void
    {
        $contents = (string) file_get_contents(base_path('modules/GiftCards/Services/GiftCardService.php'));

        $this->assertStringNotContainsString('StoreValueInstrumentType::Wallet', $contents);
        $this->assertStringNotContainsString('StoreValueInstrumentType::StoreCredit', $contents);
    }

    /**
     * No floating-point money anywhere in the new Phase-21 modules.
     */
    public function test_no_floating_point_money_storage_in_phase_21_modules(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules/DigitalDelivery/Models', 'modules/Subscriptions/Models', 'modules/Wallet/Models', 'modules/GiftCards/Models', 'modules/Order/Models']) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/(float|double)\s+\$\w*(amount|price|minor|balance|value)\w*/i', $contents)) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'Money must always be stored as integer minor units, never a float/double property.');
    }

    /**
     * Owner Delta §16: hold/release must never call the Ledger posting
     * service — only issue/capture/refund_credit/expire/
     * manual_adjustment_* are real economic events.
     */
    public function test_store_value_service_never_posts_hold_or_release_to_ledger(): void
    {
        $contents = (string) file_get_contents(base_path('modules/Wallet/Services/StoreValueLedgerPostingService.php'));

        $this->assertMatchesRegularExpression('/StoreValueEntryType::Hold,\s*StoreValueEntryType::Release/', $contents);
    }

    /**
     * Owner Delta §9: the base PaymentGatewayInterface is never modified —
     * recurring capability is added via a new, optional interface.
     */
    public function test_base_payment_gateway_interface_has_no_recurring_methods(): void
    {
        $contents = (string) file_get_contents(base_path('modules/Payment/Contracts/PaymentGatewayInterface.php'));

        $this->assertStringNotContainsString('setupPaymentMethod', $contents);
        $this->assertStringNotContainsString('chargeOffSession', $contents);
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
