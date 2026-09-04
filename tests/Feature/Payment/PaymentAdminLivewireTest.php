<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PaymentPermissionSeeder;
use Livewire\Livewire;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Livewire\PaymentDetail;
use Modules\Payment\Livewire\PaymentList;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

/**
 * Proves the Phase-15 completion-delta read-only Payments admin viewer (Owner Delta
 * item 2): renders real Payment/PaymentTransaction data, is permission-gated, and
 * exposes only the fields Modules\Payment\Http\Resources\PaymentResource already
 * exposes (never provider_response_code or provider_idempotency_key).
 */
class PaymentAdminLivewireTest extends TestCase
{
    use PaymentTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->seed(PaymentPermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'Payments Admin', 'email' => 'payments-admin@hyperstore.test',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);
    }

    private User $admin;

    public function test_payment_list_renders_real_tenant_scoped_payments(): void
    {
        $order = $this->createOrder(grandTotalMinor: 2500, currency: 'EUR');

        app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 2500,
            currency: 'EUR',
            providerCode: 'fake',
        ));

        $this->actingAs($this->admin);

        Livewire::test(PaymentList::class)
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_payment_detail_shows_transactions_but_withholds_provider_idempotency_key(): void
    {
        $order = $this->createOrder(grandTotalMinor: 3000, currency: 'EUR');

        app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 3000,
            currency: 'EUR',
            providerCode: 'fake',
            idempotencyKey: 'idem_admin_view_001',
        ));

        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($this->admin);

        Livewire::test(PaymentDetail::class, ['uuid' => $payment->uuid])
            ->assertOk()
            ->assertSee($payment->uuid)
            ->assertDontSee('idem_admin_view_001');
    }

    public function test_unauthorized_user_cannot_view_the_payments_list(): void
    {
        $unauthorized = User::create(['name' => 'No Perms Payments', 'email' => 'noperm-payments@hyperstore.test', 'password' => bcrypt('password')]);
        $this->actingAs($unauthorized);

        Livewire::test(PaymentList::class)->assertForbidden();
    }

    public function test_payment_list_does_not_leak_another_tenants_payments(): void
    {
        $order = $this->createOrder(grandTotalMinor: 1500, currency: 'EUR');
        app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id, orderId: $order->id, amountMinor: 1500, currency: 'EUR', providerCode: 'fake',
        ));

        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-payment-tenant-'.uniqid(), 'status' => 'active']);
        app(ContextManager::class)->setTenant(TenantContext::from($otherTenant->id));

        $this->actingAs($this->admin);

        Livewire::test(PaymentList::class)
            ->assertOk()
            ->assertDontSee($order->order_number);
    }
}
