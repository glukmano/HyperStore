<?php

declare(strict_types=1);

namespace Tests\Feature\Affiliate;

use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Payables\Enums\PayoutAllocationStatus;
use App\Core\Payables\Enums\PayoutRequestStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Affiliate\Contracts\AffiliatePayableSubledgerServiceInterface;
use Modules\Affiliate\Contracts\AffiliatePayoutServiceInterface;
use Modules\Affiliate\Enums\AffiliateStatus;
use Modules\Affiliate\Exceptions\InsufficientAffiliatePayableBalanceException;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliatePayableEntry;
use Tests\TestCase;

/**
 * Proves the Owner Delta correction §1 requirement empirically: Affiliate
 * payouts run through the exact same shared App\Core\Payables\Services\AbstractPayoutOrchestrator
 * as Marketplace's Vendor payouts (see tests/Feature/Marketplace/PayoutRequestAndSettlementTest.php,
 * which this test intentionally mirrors scenario-for-scenario) — not a
 * second, independently hand-copied engine.
 */
class AffiliatePayoutOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Affiliate $affiliate;

    private AffiliatePayoutServiceInterface $payoutService;

    private AffiliatePayableSubledgerServiceInterface $subledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Affiliate Payout Tenant', 'slug' => 'aff-payout-tenant']);

        $this->affiliate = Affiliate::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Payout Affiliate',
            'status' => AffiliateStatus::Active,
            'payout_currency' => 'EUR',
            'applied_at' => now(),
        ]);

        $this->payoutService = app(AffiliatePayoutServiceInterface::class);
        $this->subledger = app(AffiliatePayableSubledgerServiceInterface::class);
    }

    public function test_payout_request_reserves_allocation_and_finalization_settles_atomically(): void
    {
        AffiliatePayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $this->affiliate->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'affiliate_conversion_item',
            'source_uuid' => 'aci_100',
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => PayableAvailabilityStatus::Available,
        ]);

        $request = $this->payoutService->requestPayout($this->tenant->id, $this->affiliate->id, 4000, 'EUR');

        $this->assertSame(PayoutRequestStatus::Requested, $request->status);
        $allocation = $request->allocations()->firstOrFail();
        $this->assertSame(4000, $allocation->allocated_amount_minor);
        $this->assertSame(PayoutAllocationStatus::Reserved, $allocation->status);

        $bal = $this->subledger->getBalances($this->tenant->id, $this->affiliate->id, 'EUR');
        $this->assertSame(4000, $bal->reservedForPayoutMinor);
        $this->assertSame(6000, $bal->withdrawableBalanceMinor);

        $admin = User::factory()->create();
        $approved = $this->payoutService->approvePayout($request->id, $admin->id);
        $this->assertSame(PayoutRequestStatus::Approved, $approved->status);

        $processing = $this->payoutService->markProcessing($approved->id);
        $this->assertSame(PayoutRequestStatus::Processing, $processing->status);

        $finalized = $this->payoutService->finalizePayout($processing->id, 'SEPA-AFF-1');
        $this->assertSame(PayoutRequestStatus::Paid, $finalized->status);
        $this->assertNotNull($finalized->paid_at);

        $this->assertSame(PayoutAllocationStatus::Consumed, $allocation->fresh()->status);

        $this->assertDatabaseHas('affiliate_payable_entries', [
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $this->affiliate->id,
            'entry_type' => PayableEntryType::PayoutDisbursement->value,
            'source_type' => 'payout_request',
            'source_uuid' => $request->uuid,
            'net_amount_minor' => 4000,
        ]);

        // Idempotent replay
        $replayed = $this->payoutService->finalizePayout($processing->id, 'SEPA-AFF-1');
        $this->assertSame(PayoutRequestStatus::Paid, $replayed->status);
        $this->assertSame(1, AffiliatePayableEntry::where('source_type', 'payout_request')->where('source_uuid', $request->uuid)->count());
    }

    public function test_payout_request_exceeding_balance_fails_closed(): void
    {
        AffiliatePayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $this->affiliate->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'affiliate_conversion_item',
            'source_uuid' => 'aci_101',
            'currency' => 'EUR',
            'amount_minor' => 5000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 5000,
            'availability_status' => PayableAvailabilityStatus::Available,
        ]);

        $this->expectException(InsufficientAffiliatePayableBalanceException::class);
        $this->payoutService->requestPayout($this->tenant->id, $this->affiliate->id, 8000, 'EUR');
    }

    public function test_pending_earning_is_not_withdrawable_until_matured(): void
    {
        $earning = $this->subledger->accrueEarning(
            tenantId: $this->tenant->id,
            affiliateId: $this->affiliate->id,
            affiliateConversionItemId: null,
            sourceType: 'affiliate_conversion_item',
            sourceUuid: 'aci_pending',
            currency: 'EUR',
            amountMinor: 10000,
            commissionMinor: 0,
        );

        $this->assertSame(PayableAvailabilityStatus::Pending, $earning->availability_status);

        $bal = $this->subledger->getBalances($this->tenant->id, $this->affiliate->id, 'EUR');
        $this->assertSame(0, $bal->withdrawableBalanceMinor);

        $matured = $this->subledger->maturePendingPayables($this->tenant->id, CarbonImmutable::now()->addDay());
        $this->assertSame(1, $matured);
        $this->assertSame(PayableAvailabilityStatus::Available, $earning->fresh()->availability_status);
    }

    public function test_payout_currency_is_scoped_no_cross_currency_allocation(): void
    {
        AffiliatePayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $this->affiliate->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'affiliate_conversion_item',
            'source_uuid' => 'aci_usd',
            'currency' => 'USD',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => PayableAvailabilityStatus::Available,
        ]);

        // A EUR payout request must not be able to draw against the USD entry.
        $this->expectException(InsufficientAffiliatePayableBalanceException::class);
        $this->payoutService->requestPayout($this->tenant->id, $this->affiliate->id, 1000, 'EUR');
    }
}
