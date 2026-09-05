<?php

declare(strict_types=1);

namespace Tests\Unit\Marketplace;

use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Payables\Enums\PayoutRequestStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorVerificationStatus;
use PHPUnit\Framework\TestCase;

class VendorStatusMachinesTest extends TestCase
{
    public function test_vendor_operational_lifecycle_transitions(): void
    {
        $draft = VendorOperationalStatus::Draft;
        $this->assertTrue($draft->canTransitionTo(VendorOperationalStatus::PendingApproval));
        $this->assertTrue($draft->canTransitionTo(VendorOperationalStatus::Terminated));
        $this->assertFalse($draft->canTransitionTo(VendorOperationalStatus::Active));
        $this->assertFalse($draft->canSell());

        $pending = VendorOperationalStatus::PendingApproval;
        $this->assertTrue($pending->canTransitionTo(VendorOperationalStatus::Active));
        $this->assertTrue($pending->canTransitionTo(VendorOperationalStatus::Draft));
        $this->assertTrue($pending->canTransitionTo(VendorOperationalStatus::Suspended));

        $active = VendorOperationalStatus::Active;
        $this->assertTrue($active->canSell());
        $this->assertTrue($active->canTransitionTo(VendorOperationalStatus::Suspended));
        $this->assertTrue($active->canTransitionTo(VendorOperationalStatus::Terminated));

        $suspended = VendorOperationalStatus::Suspended;
        $this->assertFalse($suspended->canSell());
        $this->assertTrue($suspended->canTransitionTo(VendorOperationalStatus::Active));
        $this->assertTrue($suspended->canTransitionTo(VendorOperationalStatus::Terminated));

        $terminated = VendorOperationalStatus::Terminated;
        $this->assertTrue($terminated->isTerminal());
        $this->assertFalse($terminated->canSell());
        $this->assertFalse($terminated->canTransitionTo(VendorOperationalStatus::Active));
    }

    public function test_vendor_verification_lifecycle(): void
    {
        $unverified = VendorVerificationStatus::Unverified;
        $this->assertFalse($unverified->isVerified());
        $this->assertTrue($unverified->canTransitionTo(VendorVerificationStatus::Pending));

        $verified = VendorVerificationStatus::Verified;
        $this->assertTrue($verified->isVerified());
        $this->assertTrue($verified->canTransitionTo(VendorVerificationStatus::NeedsReview));
    }

    public function test_vendor_payable_entry_type_polarity(): void
    {
        $this->assertTrue(PayableEntryType::Earning->isCredit());
        $this->assertFalse(PayableEntryType::Earning->isDebit());
        $this->assertSame(1, PayableEntryType::Earning->polarityMultiplier());

        $this->assertTrue(PayableEntryType::RefundAdjustment->isDebit());
        $this->assertFalse(PayableEntryType::RefundAdjustment->isCredit());
        $this->assertSame(-1, PayableEntryType::RefundAdjustment->polarityMultiplier());

        $this->assertTrue(PayableEntryType::PayoutDisbursement->isDebit());
        $this->assertFalse(PayableEntryType::PayoutDisbursement->isCredit());
        $this->assertSame(-1, PayableEntryType::PayoutDisbursement->polarityMultiplier());
    }

    public function test_payout_request_status_transitions(): void
    {
        $req = PayoutRequestStatus::Requested;
        $this->assertTrue($req->canCancel());
        $this->assertFalse($req->isTerminal());
        $this->assertTrue($req->canTransitionTo(PayoutRequestStatus::Approved));
        $this->assertTrue($req->canTransitionTo(PayoutRequestStatus::Cancelled));

        $app = PayoutRequestStatus::Approved;
        $this->assertTrue($app->canTransitionTo(PayoutRequestStatus::Processing));
        $this->assertTrue($app->canTransitionTo(PayoutRequestStatus::Cancelled));

        $proc = PayoutRequestStatus::Processing;
        $this->assertFalse($proc->canCancel());
        $this->assertTrue($proc->canTransitionTo(PayoutRequestStatus::Paid));
        $this->assertTrue($proc->canTransitionTo(PayoutRequestStatus::Failed));

        $paid = PayoutRequestStatus::Paid;
        $this->assertTrue($paid->isTerminal());
        $this->assertFalse($paid->canTransitionTo(PayoutRequestStatus::Requested));
    }
}
