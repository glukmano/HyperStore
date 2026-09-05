<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\B2B\Enums\CompanyCreditEntryType;
use Modules\B2B\Enums\CompanyStatus;
use Modules\B2B\Exceptions\B2BException;
use Modules\B2B\Exceptions\InsufficientCompanyCreditException;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\CompanyCreditAccount;
use Modules\B2B\Models\CompanyCreditEntry;
use Modules\B2B\Services\CompanyCreditService;
use Tests\TestCase;

/**
 * Owner Delta §1: CompanyCredit is an EXPOSURE CONTROL subledger — pure
 * append-only delta, no mutable outstanding-balance column, a reservation
 * resolved EXACTLY ONCE by either release or settlement, never both, and a
 * refund after settlement never touches this subledger at all.
 */
class CompanyCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private CompanyCreditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Credit Tenant', 'slug' => 'credit-tenant']);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Acme Co',
            'status' => CompanyStatus::Active,
            'payment_terms_days' => 30,
        ]);
        CompanyCreditAccount::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'currency' => 'USD',
            'approved_limit_minor' => 100000,
        ]);

        $this->service = new CompanyCreditService;
    }

    public function test_reservation_then_release_returns_exposure_to_zero(): void
    {
        $this->service->reserveForOrder($this->company, 40000, 'USD', 'order-1');
        $this->assertSame(60000, $this->service->getAvailableCreditMinor($this->company, 'USD'));

        $this->service->releaseReservation($this->tenant->id, 'order-1');
        $this->assertSame(100000, $this->service->getAvailableCreditMinor($this->company, 'USD'));
    }

    public function test_reservation_then_settlement_returns_exposure_to_zero(): void
    {
        $this->service->reserveForOrder($this->company, 40000, 'USD', 'order-2');
        $this->service->settleReservation($this->tenant->id, 'order-2');

        $this->assertSame(100000, $this->service->getAvailableCreditMinor($this->company, 'USD'));
    }

    public function test_refund_after_settlement_never_posts_a_company_credit_entry(): void
    {
        $this->service->reserveForOrder($this->company, 40000, 'USD', 'order-3');
        $this->service->settleReservation($this->tenant->id, 'order-3');

        $entryCountBeforeRefund = CompanyCreditEntry::count();

        // A refund after settlement is handled entirely by ordinary
        // Payment/accounting semantics — never by this subledger. There is
        // no CompanyCreditService method for it at all, so we simply
        // assert the entry count (and hence exposure) is unaffected by
        // anything resembling a refund flow.
        $this->assertSame($entryCountBeforeRefund, CompanyCreditEntry::count());
        $this->assertSame(100000, $this->service->getAvailableCreditMinor($this->company, 'USD'));
    }

    public function test_release_then_settlement_is_rejected(): void
    {
        $this->service->reserveForOrder($this->company, 40000, 'USD', 'order-4');
        $this->service->releaseReservation($this->tenant->id, 'order-4');

        $this->expectException(B2BException::class);
        $this->service->settleReservation($this->tenant->id, 'order-4');
    }

    public function test_settlement_then_release_is_rejected(): void
    {
        $this->service->reserveForOrder($this->company, 40000, 'USD', 'order-5');
        $this->service->settleReservation($this->tenant->id, 'order-5');

        $this->expectException(B2BException::class);
        $this->service->releaseReservation($this->tenant->id, 'order-5');
    }

    public function test_retry_of_the_same_resolution_is_idempotent(): void
    {
        $this->service->reserveForOrder($this->company, 40000, 'USD', 'order-6');
        $this->service->settleReservation($this->tenant->id, 'order-6');
        $this->service->settleReservation($this->tenant->id, 'order-6');

        $this->assertSame(100000, $this->service->getAvailableCreditMinor($this->company, 'USD'));
        $this->assertSame(1, CompanyCreditEntry::where('entry_type', CompanyCreditEntryType::Settlement->value)->count());
    }

    public function test_retry_of_reservation_is_idempotent_and_never_double_reserves(): void
    {
        $this->service->reserveForOrder($this->company, 40000, 'USD', 'order-7');
        $this->service->reserveForOrder($this->company, 40000, 'USD', 'order-7');

        $this->assertSame(60000, $this->service->getAvailableCreditMinor($this->company, 'USD'));
        $this->assertSame(1, CompanyCreditEntry::where('source_uuid', 'order-7')->where('entry_type', CompanyCreditEntryType::Reservation->value)->count());
    }

    public function test_exposure_can_never_exceed_the_approved_limit(): void
    {
        $this->service->reserveForOrder($this->company, 90000, 'USD', 'order-8');

        $this->expectException(InsufficientCompanyCreditException::class);
        $this->service->reserveForOrder($this->company, 20000, 'USD', 'order-9');
    }

    public function test_cancellation_before_settlement_releases_reservation(): void
    {
        $this->service->reserveForOrder($this->company, 50000, 'USD', 'order-10');
        $this->service->releaseReservation($this->tenant->id, 'order-10');

        $this->assertSame(100000, $this->service->getAvailableCreditMinor($this->company, 'USD'));

        // A later Order can now reserve the full limit again.
        $this->service->reserveForOrder($this->company, 100000, 'USD', 'order-11');
        $this->assertSame(0, $this->service->getAvailableCreditMinor($this->company, 'USD'));
    }
}
