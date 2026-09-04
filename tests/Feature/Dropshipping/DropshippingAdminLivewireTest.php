<?php

declare(strict_types=1);

namespace Tests\Feature\Dropshipping;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\DropshippingPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Dropshipping\Livewire\PurchaseOrderDetail;
use Modules\Dropshipping\Livewire\PurchaseOrderList;
use Modules\Dropshipping\Livewire\SupplierDetail;
use Modules\Dropshipping\Livewire\SupplierList;
use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Dropshipping\Models\PurchaseOrderLine;
use Modules\Dropshipping\Models\Supplier;
use Tests\TestCase;

class DropshippingAdminLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Supplier $supplier;

    private PurchaseOrder $purchaseOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
        $this->seed(DropshippingPermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'DS Admin Tenant', 'slug' => 'ds-admin-tenant', 'is_active' => true]);
        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));

        $this->supplier = Supplier::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'scope_type' => 'platform',
            'name' => 'Acme Dropship Supplier',
            'code' => 'ACME_DS',
            'contact_email' => 'acme@supplier.test',
            'currency' => 'EUR',
            'status' => 'active',
            'is_dropship_capable' => true,
            'lead_time_days' => 3,
            'rating_score' => 4,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'PROD-DS-ADMIN-001',
            'name' => 'Dropship Admin Product',
            'slug' => 'dropship-admin-product-'.uniqid(),
            'product_type' => 'physical',
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'sku' => 'SKU-DS-ADMIN-001',
            'combination_hash' => 'hash-ds-admin-001',
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->purchaseOrder = PurchaseOrder::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-DS-ADMIN-001',
            'type' => 'dropship',
            'status' => 'draft',
            'currency' => 'EUR',
            'subtotal_minor' => 1200,
            'tax_minor' => 0,
            'shipping_minor' => 0,
            'total_minor' => 1200,
        ]);

        PurchaseOrderLine::create([
            'tenant_id' => $this->tenant->id,
            'purchase_order_id' => $this->purchaseOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'supplier_sku' => 'ACME-SKU-001',
            'internal_sku_snapshot' => $variant->sku,
            'quantity' => '1.00000000',
            'unit_cost_minor' => 1200,
            'total_cost_minor' => 1200,
        ]);
    }

    private function actingAsSuperAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@ds-admin-tenant.test',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_supplier_list_renders_tenant_scoped_suppliers(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(SupplierList::class)
            ->assertSee($this->supplier->name)
            ->assertSee($this->supplier->code);
    }

    public function test_purchase_order_list_renders_tenant_scoped_purchase_orders(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(PurchaseOrderList::class)
            ->assertSee($this->purchaseOrder->po_number)
            ->assertSee($this->supplier->name);
    }

    public function test_supplier_detail_shows_recent_purchase_orders(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(SupplierDetail::class, ['supplierId' => $this->supplier->id])
            ->assertSee($this->supplier->name)
            ->assertSee($this->purchaseOrder->po_number);
    }

    public function test_purchase_order_detail_is_read_only_with_no_mutating_action(): void
    {
        $this->actingAsSuperAdmin();

        // DropshipOrderOrchestratorInterface exposes no status-transition method, so the detail
        // screen intentionally has no mutating action wired to any button — the rendered markup
        // must not contain any wire:click mutation, only the read-only header/lines/invoices.
        Livewire::test(PurchaseOrderDetail::class, ['purchaseOrderId' => $this->purchaseOrder->id])
            ->assertSee($this->purchaseOrder->po_number)
            ->assertSee('ACME-SKU-001')
            ->assertDontSee('wire:click', false)
            ->assertOk();
    }

    public function test_unauthorized_user_receives_403_on_supplier_and_purchase_order_screens(): void
    {
        $unauthorized = User::create([
            'name' => 'No Perms',
            'email' => 'noperm-'.uniqid().'@ds-admin-tenant.test',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($unauthorized);

        Livewire::test(SupplierList::class)->assertForbidden();
        Livewire::test(PurchaseOrderList::class)->assertForbidden();
        Livewire::test(SupplierDetail::class, ['supplierId' => $this->supplier->id])->assertForbidden();
        Livewire::test(PurchaseOrderDetail::class, ['purchaseOrderId' => $this->purchaseOrder->id])->assertForbidden();
    }

    public function test_supplier_detail_404s_for_supplier_outside_current_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other DS Tenant', 'slug' => 'other-ds-tenant', 'is_active' => true]);

        $foreignSupplier = Supplier::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $otherTenant->id,
            'scope_type' => 'platform',
            'name' => 'Foreign Supplier',
            'code' => 'FOREIGN_DS',
            'contact_email' => 'foreign@supplier.test',
            'currency' => 'EUR',
            'status' => 'active',
        ]);

        $this->actingAsSuperAdmin();

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(SupplierDetail::class, ['supplierId' => $foreignSupplier->id]);
    }

    public function test_purchase_order_detail_404s_for_purchase_order_outside_current_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other DS Tenant 2', 'slug' => 'other-ds-tenant-2', 'is_active' => true]);

        $foreignSupplier = Supplier::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $otherTenant->id,
            'scope_type' => 'platform',
            'name' => 'Foreign Supplier 2',
            'code' => 'FOREIGN_DS_2',
            'contact_email' => 'foreign2@supplier.test',
            'currency' => 'EUR',
            'status' => 'active',
        ]);

        $foreignPo = PurchaseOrder::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $otherTenant->id,
            'supplier_id' => $foreignSupplier->id,
            'po_number' => 'PO-FOREIGN-001',
            'type' => 'dropship',
            'status' => 'draft',
            'currency' => 'EUR',
            'subtotal_minor' => 500,
            'tax_minor' => 0,
            'shipping_minor' => 0,
            'total_minor' => 500,
        ]);

        $this->actingAsSuperAdmin();

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(PurchaseOrderDetail::class, ['purchaseOrderId' => $foreignPo->id]);
    }
}
