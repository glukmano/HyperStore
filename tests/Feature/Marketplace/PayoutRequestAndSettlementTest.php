<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Contracts\PayoutServiceInterface;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\Enums\PayoutAllocationStatus;
use Modules\Marketplace\Enums\PayoutRequestStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorPayableAvailabilityStatus;
use Modules\Marketplace\Enums\VendorPayableEntryType;
use Modules\Marketplace\Exceptions\InsufficientPayableBalanceException;
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
        // 1. Accrue 10,000 EUR earning
        $earning = VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'oi_100',
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
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

        // 4. Finalize payout
        $finalized = $this->payoutService->finalizePayout($approved->id);
        $this->assertSame(PayoutRequestStatus::Paid, $finalized->status);
        $this->assertNotNull($finalized->paid_at);

        // Assert allocation consumed
        $this->assertSame(PayoutAllocationStatus::Consumed, $allocation->fresh()->status);

        // Assert payout_disbursement created
        $this->assertDatabaseHas('vendor_payable_entries', [
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::PayoutDisbursement->value,
            'source_type' => 'payout_request',
            'source_uuid' => $request->uuid,
            'net_amount_minor' => 4000,
        ]);

        // Balances: Economic = 6,000 | Reserved = 0 | Withdrawable = 6,000
        $balAfter = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(6000, $balAfter->availableEconomicBalanceMinor);
        $this->assertSame(0, $balAfter->reservedForPayoutMinor);
        $this->assertSame(6000, $balAfter->withdrawableBalanceMinor);

        // 5. Idempotent replay: calling finalize again returns paid state without second disbursement
        $replayed = $this->payoutService->finalizePayout($approved->id);
        $this->assertSame(PayoutRequestStatus::Paid, $replayed->status);

        $disbursementCount = VendorPayableEntry::where('source_type', 'payout_request')
            ->where('source_uuid', $request->uuid)
            ->count();
        $this->assertSame(1, $disbursementCount);
    }

    public function test_payout_request_exceeding_balance_fails_closed(): void
    {
        VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'oi_101',
            'currency' => 'EUR',
            'amount_minor' => 5000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 5000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        $this->expectException(InsufficientPayableBalanceException::class);
        $this->payoutService->requestPayout(
            tenantId: $this->tenant->id,
            vendorId: $this->vendor->id,
            amountMinor: 8000,
            currency: 'EUR'
        );
    }
}
