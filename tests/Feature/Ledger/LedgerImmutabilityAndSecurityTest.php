<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Contracts\LedgerPostingServiceInterface;
use Modules\Ledger\DTOs\JournalDraftDTO;
use Modules\Ledger\DTOs\JournalLineDTO;
use Modules\Ledger\Enums\JournalDirection;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Exceptions\CrossTenantAccessException;
use Modules\Ledger\Exceptions\ImmutableFinancialRecordException;
use Modules\Ledger\Listeners\PaymentEventAdapter;
use Modules\Ledger\Models\JournalEntry;
use Modules\Payment\Events\PaymentCaptured;
use Tests\TestCase;

class LedgerImmutabilityAndSecurityTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
    }

    public function test_eloquent_model_hooks_reject_mutation_before_sql(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        $journal = JournalEntry::where('source_uuid', $tx->uuid)->firstOrFail();

        $this->expectException(ImmutableFinancialRecordException::class);
        $journal->description = 'Modified in application';
        $journal->save();
    }

    public function test_composite_tenant_foreign_key_rejects_cross_tenant_account_injection(): void
    {
        /** @var LedgerAccountRegistryInterface $registry */
        $registry = app(LedgerAccountRegistryInterface::class);
        $registry->ensureRequiredSystemAccounts($this->tenant->id);

        $clearingA = $registry->getAccountByRole($this->tenant->id, SystemAccountRole::PAYMENT_CLEARING);

        // Create Tenant B
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b-'.uniqid(), 'status' => 'active']);
        $registry->ensureRequiredSystemAccounts($tenantB->id);
        $liabilityB = $registry->getAccountByRole($tenantB->id, SystemAccountRole::CUSTOMER_FUNDS_LIABILITY);

        /** @var LedgerPostingServiceInterface $postingService */
        $postingService = app(LedgerPostingServiceInterface::class);

        $now = CarbonImmutable::now('UTC');
        $draft = new JournalDraftDTO(
            tenantId: $this->tenant->id,
            sourceModule: 'test',
            sourceType: 'test',
            sourceUuid: 'cross-tenant-1',
            postingType: 'capture',
            currency: 'EUR',
            description: 'Cross tenant attempt',
            effectiveAt: $now,
            postedAt: $now,
            lines: [
                new JournalLineDTO((int) $clearingA->id, JournalDirection::DEBIT, 1000, 'EUR'),
                new JournalLineDTO((int) $liabilityB->id, JournalDirection::CREDIT, 1000, 'EUR'), // Tenant B account!
            ]
        );

        $this->expectException(CrossTenantAccessException::class);
        $postingService->post($draft);
    }

    public function test_tenant_deletion_fails_closed_due_to_posted_financial_history(): void
    {
        $order = $this->createOrder(5000, 'EUR');
        [$payment, $tx] = $this->createPaymentWithTransaction($order, 'purchase', 'success', 5000, 'EUR');

        $adapter = app(PaymentEventAdapter::class);
        $adapter->handle(new PaymentCaptured($payment, $tx));

        // Attempting to delete the tenant must fail due to ON DELETE RESTRICT
        $this->expectException(QueryException::class);
        $this->tenant->delete();
    }

    public function test_admin_rbac_requires_permissions_for_ledger_endpoints(): void
    {
        $unauthenticated = $this->getJson('/api/v1/admin/ledger/accounts');
        $unauthenticated->assertStatus(401);

        $forbidden = $this->actingAs($this->user)->getJson('/api/v1/admin/ledger/accounts');
        $forbidden->assertStatus(403);
    }
}
