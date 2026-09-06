<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Modules\POS\DTOs\PosTenderSelection;
use Modules\POS\Exceptions\UnsupportedTenderCombinationException;
use Tests\TestCase;

/**
 * Phase-22 Owner Delta architecture guarantees — grep-based structural
 * tests proving the required invariants hold codebase-wide.
 */
class Phase22ArchitectureTest extends TestCase
{
    /**
     * A completed POS sale is always an ordinary Order — no PosSale/second
     * commercial-truth model, and no second Checkout/Order/Payment engine.
     */
    public function test_no_second_commerce_core_exists_in_pos(): void
    {
        $forbiddenClassPatterns = [
            '/\bclass\s+PosSale\b/',
            '/\bclass\s+PosOrder\b/',
            '/\bclass\s+CheckoutOrchestrator\b/',
            '/\bclass\s+OrderCreationService\b/',
            '/\bclass\s+PaymentInitiationService\b/',
        ];

        $hits = [];
        foreach ($this->phpFiles(['modules/POS']) as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($forbiddenClassPatterns as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $hits[] = $file;
                }
            }
        }

        $this->assertSame([], $hits, 'modules/POS must never define a parallel Checkout/Order/Payment engine or a PosSale/PosOrder model — reuse the existing pipeline.');
    }

    /**
     * Owner Delta §3/§6: cash movements are append-only — no UPDATE
     * statement targets pos_cash_movements anywhere except via ::create().
     */
    public function test_cash_movements_are_never_mutated_after_creation(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules/POS']) as $file) {
            if (str_ends_with($file, 'PosCashMovement.php')) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            if (preg_match('/PosCashMovement::\s*(where|find|query)\([^)]*\)[^;]*->(update|save)\s*\(/s', $contents)) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'PosCashMovement rows must be append-only — never updated after creation.');
    }

    /**
     * No floating-point money anywhere in new POS code — every monetary
     * value is an integer minor-unit column/parameter.
     */
    public function test_no_floating_point_money_types_in_pos(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['modules/POS']) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/:\s*float\s*\$\w*(minor|amount|price|cash)/i', $contents)) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'No floating-point-typed monetary parameter may exist in modules/POS.');
    }

    /**
     * Owner Delta §2: POS Order context validation must never be wrapped
     * in a try/catch that would silently swallow a context failure —
     * unlike the Affiliate soft-hook pattern.
     */
    public function test_pos_order_context_hook_call_site_has_no_surrounding_try_catch(): void
    {
        $contents = (string) file_get_contents(base_path('modules/Order/Services/OrderCreationService.php'));

        $pos = strpos($contents, 'PosOrderContextHookInterface::class)->validateAndFreezePosContext');
        $this->assertNotFalse($pos, 'The PosOrderContextHookInterface call site must exist in OrderCreationService.');

        // Look back a reasonable window for an opening try{ with no
        // intervening catch before this call (a crude but effective proxy:
        // the nearest preceding "try {" within 200 chars must not exist).
        $window = substr($contents, max(0, $pos - 200), 200);
        $this->assertStringNotContainsString('try {', $window, 'The POS context hook call must not be wrapped in a try/catch.');
    }

    /**
     * Owner Delta §6: cash + card in the same sale is rejected at the DTO
     * boundary — codified once, not scattered as ad-hoc checks.
     */
    public function test_tender_selection_rejects_cash_and_card_together(): void
    {
        $this->expectException(UnsupportedTenderCombinationException::class);
        new PosTenderSelection(cashAmountMinor: 100, cardProviderCode: 'fake');
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
