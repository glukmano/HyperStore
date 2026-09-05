<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Payables\Enums\PayoutAllocationStatus;
use App\Core\Payables\Enums\PayoutRequestStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Contracts\PayoutServiceInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\InsufficientPayableBalanceException;
use Modules\Marketplace\Exceptions\PayoutFinalizationException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPayableEntry;
use Modules\Marketplace\Models\VendorPlan;
use Tests\TestCase;

class PayoutRequestAndSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Vendor $vendor;

    private PayoutServiceInterface $payoutService;

    private VendorPayableSubledgerServiceInterface $subledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Payout Tenant',
            'slug' => 'payout-tenant',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                    'payable_hold_days' => 14,
                ],
            ],
        ]);

        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Payout Plan',
            'code' => 'payout-plan',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Payout Vendor',
            'platform_slug' => 'payout-vendor',
            'legal_name' => 'Payout Vendor Corp',
            'email' => 'payout@vendor.com',
            'payout_currency' => 'EUR',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        $this->payoutService = app(PayoutServiceInterface::class);
        $this->subledger = app(VendorPayableSubledgerServiceInterface::class);
    }

    public function test_payout_request_reserves_allocation_and_finalization_settles_atomically(): void
    {
        // 1. Accrue 10,000 EUR earning (available)
        VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'oi_100',
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => PayableAvailabilityStatus::Available,
        ]);

        // 2. Request 4,000 EUR payout
        $request = $this->payoutService->requestPayout(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            amountMinor: 4000,
            currency: 'EUR'
        );

        $this->assertSame(PayoutRequestStatus::Requested, $request->status);
        $this->assertSame(4000, $request->amount_minor);

        // Assert allocation created
        $allocation = $request->allocations()->firstOrFail();
        $this->assertSame(4000, $allocation->allocated_amount_minor);
        $this->assertSame(PayoutAllocationStatus::Reserved, $allocation->status);

        // Balances: Economic = 10,000 | Reserved = 4,000 | Withdrawable = 6,000
        $bal = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(10000, $bal->availableEconomicBalanceMinor);
        $this->assertSame(4000, $bal->reservedForPayoutMinor);
        $this->assertSame(6000, $bal->withdrawableBalanceMinor);

        // 3. Approve payout
        $adminUser = User::factory()->create();
        $approved = $this->payoutService->approvePayout($request->id, $adminUser->id);
        $this->assertSame(PayoutRequestStatus::Approved, $approved->status);

        // 4. Mark processing
        $processing = $this->payoutService->markProcessing($approved->id);
        $this->assertSame(PayoutRequestStatus::Processing, $processing->status);

        // 5. Finalize payout with settlement evidence
        $finalized = $this->payoutService->finalizePayout(
            payoutRequestId: $processing->id,
            settlementReference: 'SEPA-TX-998811',
            settlementMetadata: ['channel' => 'manual_wire', 'batch' => 'B10']
        );
        $this->assertSame(PayoutRequestStatus::Paid, $finalized->status);
        $this->assertNotNull($finalized->paid_at);
        $this->assertSame('SEPA-TX-998811', $finalized->destination_details['settlement']['reference']);

        // Assert allocation consumed
        $this->assertSame(PayoutAllocationStatus::Consumed, $allocation->fresh()->status);

        // Assert payout_disbursement created
        $this->assertDatabaseHas('vendor_payable_entries', [
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => PayableEntryType::PayoutDisbursement->value,
            'source_type' => 'payout_request',
            'source_uuid' => $request->uuid,
            'net_amount_minor' => 4000,
        ]);

        // Balances: Economic = 6,000 | Reserved = 0 | Withdrawable = 6,000
        $balAfter = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(6000, $balAfter->availableEconomicBalanceMinor);
        $this->assertSame(0, $balAfter->reservedForPayoutMinor);
        $this->assertSame(6000, $balAfter->withdrawableBalanceMinor);

        // 6. Idempotent replay: calling finalize again returns paid state without second disbursement
        $replayed = $this->payoutService->finalizePayout($processing->id, 'SEPA-TX-998811');
        $this->assertSame(PayoutRequestStatus::Paid, $replayed->status);

        $disbursementCount = VendorPayableEntry::where('source_type', 'payout_request')
            ->where('source_uuid', $request->uuid)
            ->count();
        $this->assertSame(1, $disbursementCount);
    }

    public function test_finalize_without_processing_fails_closed(): void
    {
        VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'oi_direct',
            'currency' => 'EUR',
            'amount_minor' => 5000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 5000,
            'availability_status' => PayableAvailabilityStatus::Available,
        ]);

        $request = $this->payoutService->requestPayout($this->tenant->id, $this->vendor->id, 2000, 'EUR');
        $adminUser = User::factory()->create();
        $approved = $this->payoutService->approvePayout($request->id, $adminUser->id);

        // Attempt finalize without markProcessing -> must fail
        $this->expectException(PayoutFinalizationException::class);
        $this->expectExceptionMessage("expected 'processing'");
        $this->payoutService->finalizePayout($approved->id, 'TX-REF-DIRECT');
    }

    public function test_payout_request_exceeding_balance_fails_closed(): void
    {
        VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'oi_101',
            'currency' => 'EUR',
            'amount_minor' => 5000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 5000,
            'availability_status' => PayableAvailabilityStatus::Available,
        ]);

        $this->expectException(InsufficientPayableBalanceException::class);
        $this->payoutService->requestPayout(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            amountMinor: 8000,
            currency: 'EUR'
        );
    }

    public function test_payout_safety_lifecycle_pending_maturity_and_held_invariants(): void
    {
        // 1. Accrue new earning through subledger: begins as PENDING
        $earning = $this->subledger->accrueEarning(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            orderItemId: null,
            sourceType: 'order_item',
            sourceUuid: 'oi_pending_test',
            currency: 'EUR',
            amountMinor: 10000,
            commissionMinor: 1500
        );

        $this->assertNotNull($earning);
        $this->assertSame(PayableAvailabilityStatus::Pending, $earning->availability_status);
        $this->assertSame(8500, $earning->net_amount_minor);
        $this->assertNotNull($earning->available_at);

        // 2. Balances check: Withdrawable balance is ZERO
        $bal = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(8500, $bal->pendingBalanceMinor);
        $this->assertSame(0, $bal->availableEconomicBalanceMinor);
        $this->assertSame(0, $bal->withdrawableBalanceMinor);

        // 3. Attempt payout before maturity: REJECTED with InsufficientPayableBalanceException
        try {
            $this->payoutService->requestPayout($this->tenant->id, $this->vendor->id, 5000, 'EUR');
            $this->fail('Expected payout request on pending funds to fail.');
        } catch (InsufficientPayableBalanceException $e) {
            $this->assertStringContainsString('exceeds available withdrawable balance', $e->getMessage());
        }

        // 4. Maturity processing before cutoff: 0 matured
        $matured = $this->subledger->maturePendingPayables($this->tenant->id, CarbonImmutable::now());
        $this->assertSame(0, $matured);

        // 5. Maturity processing at future cutoff (>= available_at): 1 matured!
        $futureCutoff = CarbonImmutable::now()->addDays(15);
        $matured = $this->subledger->maturePendingPayables($this->tenant->id, $futureCutoff);
        $this->assertSame(1, $matured);
        $this->assertSame(PayableAvailabilityStatus::Available, $earning->fresh()->availability_status);

        // Withdrawable balance now equals 8,500
        $balAfterMaturity = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(0, $balAfterMaturity->pendingBalanceMinor);
        $this->assertSame(8500, $balAfterMaturity->withdrawableBalanceMinor);

        // 6. Held test: If an entry is put on hold, it cannot mature or be withdrawn
        $entry2 = $this->subledger->accrueEarning(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            orderItemId: null,
            sourceType: 'order_item',
            sourceUuid: 'oi_held_test',
            currency: 'EUR',
            amountMinor: 3000,
            commissionMinor: 0
        );
        $this->subledger->holdEntry($entry2->id, 'KYC review hold');
        $this->assertSame(PayableAvailabilityStatus::Held, $entry2->fresh()->availability_status);

        // Even with future maturity run, held entry remains held!
        $this->subledger->maturePendingPayables($this->tenant->id, $futureCutoff);
        $this->assertSame(PayableAvailabilityStatus::Held, $entry2->fresh()->availability_status);
    }
}
