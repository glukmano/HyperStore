<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Marketplace\Enums\PayoutAllocationStatus;
use Modules\Marketplace\Enums\PayoutRequestStatus;
use Modules\Marketplace\Enums\VendorPayableAvailabilityStatus;
use Modules\Marketplace\Enums\VendorPayableEntryType;
use Modules\Marketplace\Models\PayoutRequest;
use Modules\Marketplace\Models\PayoutRequestAllocation;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPayableEntry;
use Modules\Marketplace\Models\VendorPlan;
use Tests\TestCase;

class VendorBalanceEquationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Vendor $vendor;

    private VendorPayableSubledgerServiceInterface $subledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Balance Tenant',
            'slug' => 'bal-tenant',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                ],
            ],
        ]);

        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Basic Plan',
            'code' => 'basic',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Balance Vendor',
            'platform_slug' => 'balance-vendor',
            'legal_name' => 'Balance Vendor Corp',
            'email' => 'bal@vendor.com',
            'payout_currency' => 'EUR',
        ]);

        $this->subledger = app(VendorPayableSubledgerServiceInterface::class);
    }

    public function test_complete_payout_lifecycle_with_zero_double_subtraction(): void
    {
        // 1. Initial earning of +10,000 minor units
        $earning = VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'test_order',
            'source_uuid' => 'order-item-1',
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        $bal1 = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(10000, $bal1->availableEconomicBalanceMinor);
        $this->assertSame(0, $bal1->reservedForPayoutMinor);
        $this->assertSame(10000, $bal1->withdrawableBalanceMinor);
        $this->assertSame(10000, $this->subledger->getSourceRemainingAllocatableMinor($earning->id));

        // 2. Request A reserves 4,000 minor units
        $reqA = PayoutRequest::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'amount_minor' => 4000,
            'currency' => 'EUR',
            'status' => PayoutRequestStatus::Requested,
        ]);

        $allocA = PayoutRequestAllocation::create([
            'tenant_id' => $this->tenant->id,
            'payout_request_id' => $reqA->id,
            'vendor_payable_entry_id' => $earning->id,
            'allocated_amount_minor' => 4000,
            'status' => PayoutAllocationStatus::Reserved,
        ]);

        $bal2 = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(10000, $bal2->availableEconomicBalanceMinor);
        $this->assertSame(4000, $bal2->reservedForPayoutMinor);
        $this->assertSame(6000, $bal2->withdrawableBalanceMinor);
        $this->assertSame(6000, $this->subledger->getSourceRemainingAllocatableMinor($earning->id));

        // 3. Request A PAID (settled): allocation -> consumed, append payout_disbursement
        $allocA->status = PayoutAllocationStatus::Consumed;
        $allocA->save();
        $reqA->status = PayoutRequestStatus::Paid;
        $reqA->save();

        VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::PayoutDisbursement,
            'source_type' => 'payout_request',
            'source_uuid' => $reqA->uuid,
            'currency' => 'EUR',
            'amount_minor' => 4000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 4000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        // Assert: NO double subtraction! Economic balance is 6,000, NOT 2,000!
        $bal3 = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(6000, $bal3->availableEconomicBalanceMinor);
        $this->assertSame(0, $bal3->reservedForPayoutMinor);
        $this->assertSame(6000, $bal3->withdrawableBalanceMinor);
        $this->assertSame(6000, $this->subledger->getSourceRemainingAllocatableMinor($earning->id));

        // 4. Request B reserves remaining 6,000 minor units
        $reqB = PayoutRequest::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'amount_minor' => 6000,
            'currency' => 'EUR',
            'status' => PayoutRequestStatus::Requested,
        ]);

        $allocB = PayoutRequestAllocation::create([
            'tenant_id' => $this->tenant->id,
            'payout_request_id' => $reqB->id,
            'vendor_payable_entry_id' => $earning->id,
            'allocated_amount_minor' => 6000,
            'status' => PayoutAllocationStatus::Reserved,
        ]);

        $bal4 = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(6000, $bal4->availableEconomicBalanceMinor);
        $this->assertSame(6000, $bal4->reservedForPayoutMinor);
        $this->assertSame(0, $bal4->withdrawableBalanceMinor);
        $this->assertSame(0, $this->subledger->getSourceRemainingAllocatableMinor($earning->id));

        // 5. Request B PAID: allocation -> consumed, append payout_disbursement
        $allocB->status = PayoutAllocationStatus::Consumed;
        $allocB->save();
        $reqB->status = PayoutRequestStatus::Paid;
        $reqB->save();

        VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::PayoutDisbursement,
            'source_type' => 'payout_request',
            'source_uuid' => $reqB->uuid,
            'currency' => 'EUR',
            'amount_minor' => 6000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 6000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        // Final balance is exactly zero
        $bal5 = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(0, $bal5->availableEconomicBalanceMinor);
        $this->assertSame(0, $bal5->reservedForPayoutMinor);
        $this->assertSame(0, $bal5->withdrawableBalanceMinor);
        $this->assertSame(0, $this->subledger->getSourceRemainingAllocatableMinor($earning->id));
    }

    public function test_failed_payout_releases_reservation_cleanly(): void
    {
        $earning = VendorPayableEntry::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'entry_type' => VendorPayableEntryType::Earning,
            'source_type' => 'test_order',
            'source_uuid' => 'order-item-2',
            'currency' => 'EUR',
            'amount_minor' => 10000,
            'commission_amount_minor' => 0,
            'net_amount_minor' => 10000,
            'availability_status' => VendorPayableAvailabilityStatus::Available,
        ]);

        $req = PayoutRequest::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'amount_minor' => 4000,
            'currency' => 'EUR',
            'status' => PayoutRequestStatus::Requested,
        ]);

        $alloc = PayoutRequestAllocation::create([
            'tenant_id' => $this->tenant->id,
            'payout_request_id' => $req->id,
            'vendor_payable_entry_id' => $earning->id,
            'allocated_amount_minor' => 4000,
            'status' => PayoutAllocationStatus::Reserved,
        ]);

        // Payout fails prior to authoritative execution
        $alloc->status = PayoutAllocationStatus::Released;
        $alloc->save();
        $req->status = PayoutRequestStatus::Failed;
        $req->save();

        // Liquidity restored
        $bal = $this->subledger->getBalances($this->tenant->id, $this->vendor->id, 'EUR');
        $this->assertSame(10000, $bal->availableEconomicBalanceMinor);
        $this->assertSame(0, $bal->reservedForPayoutMinor);
        $this->assertSame(10000, $bal->withdrawableBalanceMinor);
        $this->assertSame(10000, $this->subledger->getSourceRemainingAllocatableMinor($earning->id));
    }
}
