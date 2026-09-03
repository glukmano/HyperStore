<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        // 1. Additive changes to existing tables
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('commercial_model_snapshot', 64)->nullable();
            $table->unique(['tenant_id', 'id'], 'uq_orders_tenant_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('requires_shipping_snapshot')->nullable();
            $table->unique(['tenant_id', 'id'], 'uq_order_items_tenant_id');
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'uq_payment_transactions_tenant_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'uq_products_tenant_id');
        });

        // 2. seller_orders
        Schema::create('seller_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_id');
            $table->string('seller_order_number', 64);
            $table->string('seller_type', 32);
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('commercial_model', 64);
            $table->string('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('shipping_original_minor')->default(0);
            $table->bigInteger('shipping_discount_minor')->default(0);
            $table->bigInteger('shipping_final_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->bigInteger('commission_total_minor')->default(0);
            $table->string('status', 32)->default('open');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'uq_seller_orders_composite');
            $table->unique(['tenant_id', 'seller_order_number'], 'uq_seller_orders_number');
            $table->foreign('tenant_id', 'fk_so_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('store_id', 'fk_so_store')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('vendor_id', 'fk_so_vendor')->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'order_id'], 'fk_so_tenant_order')
                ->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
        });

        if ($isPgsql) {
            DB::statement("
                ALTER TABLE seller_orders ADD CONSTRAINT chk_seller_orders_type CHECK (
                    (seller_type = 'platform' AND vendor_id IS NULL) OR
                    (seller_type = 'vendor' AND vendor_id IS NOT NULL)
                )
            ");

            DB::statement("
                CREATE UNIQUE INDEX uq_seller_orders_platform 
                ON seller_orders (tenant_id, order_id) 
                WHERE seller_type = 'platform'
            ");

            DB::statement("
                CREATE UNIQUE INDEX uq_seller_orders_vendor 
                ON seller_orders (tenant_id, order_id, vendor_id) 
                WHERE seller_type = 'vendor'
            ");
        }

        // 3. seller_order_items
        Schema::create('seller_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('seller_order_id');
            $table->unsignedBigInteger('order_item_id');
            $table->decimal('quantity', 20, 8);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('commission_minor')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'order_item_id'], 'uq_soi_tenant_order_item');
            $table->unique(['seller_order_id', 'order_item_id'], 'uq_soi_seller_order_item');
            $table->foreign('tenant_id', 'fk_soi_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'seller_order_id'], 'fk_soi_tenant_seller_order')
                ->references(['tenant_id', 'id'])->on('seller_orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_item_id'], 'fk_soi_tenant_order_item')
                ->references(['tenant_id', 'id'])->on('order_items')->restrictOnDelete();
        });

        // 4. suppliers
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('scope_type', 32);
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('currency', 3);
            $table->boolean('is_dropship_capable')->default(false);
            $table->integer('lead_time_days')->default(1);
            $table->bigInteger('min_order_value_minor')->default(0);
            $table->integer('rating_score')->default(100);
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'scope_type'], 'uq_suppliers_scope_id');
            $table->foreign('tenant_id', 'fk_suppliers_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_suppliers_private_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->cascadeOnDelete();
        });

        if ($isPgsql) {
            DB::statement("
                ALTER TABLE suppliers ADD CONSTRAINT chk_suppliers_scope CHECK (
                    (scope_type = 'platform' AND tenant_id IS NULL AND vendor_id IS NULL) OR
                    (scope_type = 'tenant' AND tenant_id IS NOT NULL AND vendor_id IS NULL) OR
                    (scope_type = 'private_vendor' AND tenant_id IS NOT NULL AND vendor_id IS NOT NULL)
                )
            ");

            DB::statement("
                CREATE UNIQUE INDEX uq_suppliers_platform_code 
                ON suppliers (code) 
                WHERE scope_type = 'platform'
            ");

            DB::statement("
                CREATE UNIQUE INDEX uq_suppliers_tenant_code 
                ON suppliers (tenant_id, code) 
                WHERE scope_type = 'tenant'
            ");

            DB::statement("
                CREATE UNIQUE INDEX uq_suppliers_vendor_code 
                ON suppliers (tenant_id, vendor_id, code) 
                WHERE scope_type = 'private_vendor'
            ");
        }

        // 5. tenant_supplier_access
        Schema::create('tenant_supplier_access', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'supplier_id'], 'uq_tenant_supplier_access');
        });

        // 6. supplier_locations
        Schema::create('supplier_locations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('country_code', 2);
            $table->string('state_province', 128)->nullable();
            $table->string('city', 128);
            $table->string('postal_code', 32);
            $table->string('address_line1', 255);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['supplier_id', 'id'], 'uq_supplier_locations_supplier_id');
            $table->unique(['supplier_id', 'code'], 'uq_supplier_locations_code');
        });

        // 7. supplier_accounts
        Schema::create('supplier_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('provider_key', 64);
            $table->string('external_account_reference', 128);
            $table->string('status', 32)->default('active');
            $table->jsonb('configuration')->nullable();
            $table->text('credentials_encrypted');
            $table->timestampTz('last_authenticated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['supplier_id', 'provider_key', 'external_account_reference'], 'uq_supplier_accounts');
        });

        // 8. supplier_sync_states
        Schema::create('supplier_sync_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('sync_type', 32);
            $table->string('status', 32)->default('idle');
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->integer('items_synced_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->unique(['supplier_id', 'sync_type'], 'uq_supplier_sync_state');
        });

        // 9. supplier_product_variants
        Schema::create('supplier_product_variants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('supplier_sku', 128);
            $table->bigInteger('canonical_wholesale_cost_minor');
            $table->string('currency', 3);
            $table->timestampsTz();

            $table->unique(['supplier_id', 'id'], 'uq_spv_supplier_id');
            $table->unique(['supplier_id', 'supplier_sku'], 'uq_spv_supplier_sku');
            $table->foreign('tenant_id', 'fk_spv_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'fk_spv_catalog_product')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign('product_variant_id', 'fk_spv_catalog_variant')
                ->references('id')->on('product_variants')->cascadeOnDelete();
        });

        if ($isPgsql) {
            DB::statement('
                CREATE UNIQUE INDEX uq_spv_simple 
                ON supplier_product_variants (supplier_id, product_id) 
                WHERE product_variant_id IS NULL
            ');

            DB::statement('
                CREATE UNIQUE INDEX uq_spv_variant 
                ON supplier_product_variants (supplier_id, product_variant_id) 
                WHERE product_variant_id IS NOT NULL
            ');
        }

        // 10. supplier_offers
        Schema::create('supplier_offers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('supplier_product_variant_id');
            $table->unsignedBigInteger('supplier_location_id');
            $table->decimal('stock_quantity', 20, 8)->default(0);
            $table->boolean('is_available')->default(true);
            $table->bigInteger('location_wholesale_cost_minor')->nullable();
            $table->integer('lead_time_days')->default(1);
            $table->timestampTz('synced_at');
            $table->timestampsTz();

            $table->unique(['supplier_product_variant_id', 'supplier_location_id'], 'uq_so_variant_location');
            $table->foreign(['supplier_id', 'supplier_product_variant_id'], 'fk_so_spv')
                ->references(['supplier_id', 'id'])->on('supplier_product_variants')->cascadeOnDelete();
            $table->foreign(['supplier_id', 'supplier_location_id'], 'fk_so_sl')
                ->references(['supplier_id', 'id'])->on('supplier_locations')->cascadeOnDelete();
        });

        // 11. order_fulfillments
        Schema::create('order_fulfillments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('seller_order_id');
            $table->unsignedBigInteger('parent_fulfillment_id')->nullable();
            $table->string('fulfillment_number', 64);
            $table->string('fulfillment_mode', 32);
            $table->foreignId('inventory_source_id')->nullable()->constrained('inventory_sources')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('supplier_location_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->jsonb('routing_snapshot')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'uq_order_fulfillments_composite');
            $table->unique(['tenant_id', 'fulfillment_number'], 'uq_order_fulfillments_number');
            $table->foreign('tenant_id', 'fk_of_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('supplier_id', 'fk_of_supplier')->references('id')->on('suppliers')->nullOnDelete();
            $table->foreign('parent_fulfillment_id', 'fk_of_parent')->references('id')->on('order_fulfillments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'seller_order_id'], 'fk_of_tenant_seller_order')
                ->references(['tenant_id', 'id'])->on('seller_orders')->restrictOnDelete();
            $table->foreign(['supplier_id', 'supplier_location_id'], 'fk_of_supplier_location')
                ->references(['supplier_id', 'id'])->on('supplier_locations')->nullOnDelete();
        });

        if ($isPgsql) {
            DB::statement("
                ALTER TABLE order_fulfillments ADD CONSTRAINT chk_hybrid_parent_rules CHECK (
                    (fulfillment_mode = 'hybrid' AND parent_fulfillment_id IS NULL AND 
                     supplier_id IS NULL AND supplier_location_id IS NULL AND 
                     inventory_source_id IS NULL AND warehouse_id IS NULL) OR
                    (fulfillment_mode != 'hybrid')
                )
            ");
        }

        // 12. order_fulfillment_items
        Schema::create('order_fulfillment_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_fulfillment_id');
            $table->unsignedBigInteger('order_item_id');
            $table->decimal('quantity', 20, 8);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'order_fulfillment_id', 'order_item_id'], 'uq_ofi_line');
            $table->foreign('tenant_id', 'fk_ofi_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_fulfillment_id'], 'fk_ofi_fulfillment')
                ->references(['tenant_id', 'id'])->on('order_fulfillments')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_item_id'], 'fk_ofi_order_item')
                ->references(['tenant_id', 'id'])->on('order_items')->restrictOnDelete();
        });

        // 13. order_shipments
        Schema::create('order_shipments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_fulfillment_id');
            $table->string('carrier_code', 64);
            $table->string('carrier_name', 255);
            $table->string('tracking_number', 255);
            $table->text('tracking_url')->nullable();
            $table->text('shipping_label_url')->nullable();
            $table->string('status', 32)->default('pending');
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'carrier_code', 'tracking_number'], 'uq_shipments_tracking');
            $table->foreign('tenant_id', 'fk_shipments_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_fulfillment_id'], 'fk_shipments_fulfillment')
                ->references(['tenant_id', 'id'])->on('order_fulfillments')->restrictOnDelete();
        });

        // 14. purchase_orders
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->unsignedBigInteger('order_fulfillment_id')->nullable();
            $table->string('po_number', 64);
            $table->string('type', 32)->default('dropship');
            $table->string('status', 32)->default('draft');
            $table->string('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('expected_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'uq_purchase_orders_composite');
            $table->unique(['tenant_id', 'po_number'], 'uq_purchase_orders_number');
            $table->foreign('tenant_id', 'fk_po_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_fulfillment_id'], 'fk_po_fulfillment')
                ->references(['tenant_id', 'id'])->on('order_fulfillments')->restrictOnDelete();
        });

        if ($isPgsql) {
            DB::statement('
                CREATE UNIQUE INDEX uq_po_dropship_fulfillment 
                ON purchase_orders (tenant_id, order_fulfillment_id) 
                WHERE order_fulfillment_id IS NOT NULL
            ');
        }

        // 15. purchase_order_lines
        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('supplier_sku', 128);
            $table->string('internal_sku_snapshot', 128);
            $table->decimal('quantity', 20, 8);
            $table->bigInteger('unit_cost_minor');
            $table->bigInteger('total_cost_minor');
            $table->timestampsTz();

            $table->unique(['purchase_order_id', 'id'], 'uq_pol_po_id');
            $table->unique(['purchase_order_id', 'supplier_sku'], 'uq_pol_supplier_sku');
            $table->foreign('tenant_id', 'fk_pol_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('purchase_order_id', 'fk_pol_po')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_item_id'], 'fk_pol_order_item')
                ->references(['tenant_id', 'id'])->on('order_items')->restrictOnDelete();
        });

        // 16. supplier_invoices
        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->string('invoice_number', 64);
            $table->string('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('shipping_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->string('status', 32)->default('received');
            $table->timestampTz('issued_at');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'uq_supplier_invoices_composite');
            $table->unique(['tenant_id', 'supplier_id', 'invoice_number'], 'uq_supplier_invoice_number');
            $table->foreign('tenant_id', 'fk_si_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'purchase_order_id'], 'fk_si_tenant_po')
                ->references(['tenant_id', 'id'])->on('purchase_orders')->restrictOnDelete();
        });

        // 17. supplier_invoice_lines
        Schema::create('supplier_invoice_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->string('supplier_sku_snapshot', 128);
            $table->string('description', 255);
            $table->decimal('quantity', 20, 8);
            $table->bigInteger('unit_cost_minor');
            $table->bigInteger('line_total_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->timestampsTz();

            $table->foreign(['purchase_order_id', 'purchase_order_line_id'], 'fk_sil_pol_composite')
                ->references(['purchase_order_id', 'id'])->on('purchase_order_lines')->restrictOnDelete();
        });

        // 18. return_requests
        Schema::create('return_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('rma_number', 64);
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('overall_status', 32)->default('requested');
            $table->text('customer_note')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'uq_return_requests_composite');
            $table->unique(['tenant_id', 'rma_number'], 'uq_return_requests_number');
            $table->foreign('tenant_id', 'fk_rr_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_id'], 'fk_rr_tenant_order')
                ->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
        });

        // 19. seller_returns
        Schema::create('seller_returns', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('return_request_id');
            $table->unsignedBigInteger('seller_order_id');
            $table->string('seller_type', 32);
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->string('seller_rma_number', 64);
            $table->string('status', 32)->default('requested');
            $table->string('refund_eligibility_status', 32)->default('pending');
            $table->uuid('refund_operation_uuid')->nullable()->unique();
            $table->unsignedBigInteger('payment_refund_transaction_id')->nullable();
            $table->string('refund_status', 32)->default('pending');
            $table->timestampTz('refund_finalized_at')->nullable();
            $table->string('reason_code', 64);
            $table->text('staff_note')->nullable();
            $table->bigInteger('refund_subtotal_minor')->default(0);
            $table->bigInteger('refund_discount_reversal_minor')->default(0);
            $table->bigInteger('refund_tax_minor')->default(0);
            $table->bigInteger('refund_shipping_minor')->default(0);
            $table->bigInteger('net_customer_refund_minor')->default(0);
            $table->bigInteger('vendor_payable_debit_minor')->default(0);
            $table->bigInteger('vendor_commission_reversal_minor')->default(0);
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'uq_seller_returns_composite');
            $table->unique(['tenant_id', 'seller_rma_number'], 'uq_seller_returns_number');
            $table->unique(['return_request_id', 'seller_order_id'], 'uq_sr_request_seller_order');
            $table->foreign('tenant_id', 'fk_sr_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vendor_id', 'fk_sr_vendor')->references('id')->on('vendors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'return_request_id'], 'fk_sr_tenant_return_request')
                ->references(['tenant_id', 'id'])->on('return_requests')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'seller_order_id'], 'fk_sr_tenant_seller_order')
                ->references(['tenant_id', 'id'])->on('seller_orders')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_refund_transaction_id'], 'fk_sr_payment_tx_tenant')
                ->references(['tenant_id', 'id'])->on('payment_transactions')->restrictOnDelete();
        });

        // 20. return_items
        Schema::create('return_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('seller_return_id');
            $table->unsignedBigInteger('order_item_id');
            $table->decimal('quantity_requested', 20, 8);
            $table->decimal('quantity_approved', 20, 8)->default(0);
            $table->decimal('quantity_received', 20, 8)->default(0);
            $table->string('condition', 32)->nullable();
            $table->string('restock_action', 32)->default('restock');
            $table->string('action', 32)->default('refund');
            $table->timestampsTz();

            $table->unique(['seller_return_id', 'order_item_id'], 'uq_ri_seller_return_item');
            $table->foreign('tenant_id', 'fk_ri_tenant')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'seller_return_id'], 'fk_ri_seller_return')
                ->references(['tenant_id', 'id'])->on('seller_returns')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_item_id'], 'fk_ri_order_item')
                ->references(['tenant_id', 'id'])->on('order_items')->restrictOnDelete();
        });

        // 21. supplier_return_references
        Schema::create('supplier_return_references', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('return_item_id')->constrained('return_items')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_rma_number', 64);
            $table->string('supplier_tracking_number', 255)->nullable();
            $table->string('status', 32)->default('initiated');
            $table->timestampsTz();

            $table->unique(['return_item_id', 'supplier_id'], 'uq_supplier_return_refs');
        });

        // 22. PostgreSQL Structural Constraint Triggers
        if ($isPgsql) {
            $this->createConstraintTriggers();
        }
    }

    private function createConstraintTriggers(): void
    {
        // Trigger 1: Purchase Order Scope Enforcement
        DB::unprepared("
            CREATE OR REPLACE FUNCTION check_purchase_order_supplier_scope()
            RETURNS TRIGGER AS $$
            DECLARE
                v_supplier suppliers%ROWTYPE;
                v_seller_order seller_orders%ROWTYPE;
                v_fulfillment order_fulfillments%ROWTYPE;
            BEGIN
                SELECT * INTO v_supplier FROM suppliers WHERE id = NEW.supplier_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Supplier % does not exist', NEW.supplier_id;
                END IF;

                -- Platform Supplier: Must be enabled in tenant_supplier_access
                IF v_supplier.scope_type = 'platform' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM tenant_supplier_access 
                        WHERE tenant_id = NEW.tenant_id AND supplier_id = v_supplier.id AND is_enabled = true
                    ) THEN
                        RAISE EXCEPTION 'Tenant % is not authorized to procure from Platform Supplier %', 
                            NEW.tenant_id, v_supplier.id;
                    END IF;
                    RETURN NEW;
                END IF;

                -- Tenant Supplier: PO tenant must match Supplier tenant
                IF v_supplier.scope_type = 'tenant' THEN
                    IF v_supplier.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                        RAISE EXCEPTION 'PurchaseOrder tenant % does not match Tenant Supplier tenant %', 
                            NEW.tenant_id, v_supplier.tenant_id;
                    END IF;
                    RETURN NEW;
                END IF;

                -- Private Vendor Supplier: PO tenant must match Supplier tenant AND SellerOrder vendor must match Supplier vendor
                IF v_supplier.scope_type = 'private_vendor' THEN
                    IF v_supplier.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                        RAISE EXCEPTION 'PurchaseOrder tenant % does not match Private Vendor Supplier tenant %', 
                            NEW.tenant_id, v_supplier.tenant_id;
                    END IF;

                    IF NEW.order_fulfillment_id IS NULL THEN
                        RAISE EXCEPTION 'Private vendor supplier purchase orders require an authoritative order_fulfillment_id';
                    END IF;

                    SELECT * INTO v_fulfillment FROM order_fulfillments WHERE id = NEW.order_fulfillment_id;
                    IF FOUND THEN
                        SELECT * INTO v_seller_order FROM seller_orders WHERE id = v_fulfillment.seller_order_id;
                        IF FOUND AND v_seller_order.vendor_id IS DISTINCT FROM v_supplier.vendor_id THEN
                            RAISE EXCEPTION 'Vendor isolation violation: SellerOrder vendor % cannot procure from Supplier owned by vendor %',
                                v_seller_order.vendor_id, v_supplier.vendor_id;
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_po_supplier_scope ON purchase_orders;
            CREATE CONSTRAINT TRIGGER trg_po_supplier_scope
            AFTER INSERT OR UPDATE ON purchase_orders
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW EXECUTE FUNCTION check_purchase_order_supplier_scope();
        ");

        // Trigger 2: Order Fulfillment Supplier Authorization & Mode Compatibility
        DB::unprepared("
            CREATE OR REPLACE FUNCTION check_fulfillment_supplier_scope()
            RETURNS TRIGGER AS $$
            DECLARE
                v_supplier suppliers%ROWTYPE;
                v_seller_order seller_orders%ROWTYPE;
            BEGIN
                IF NEW.supplier_id IS NULL THEN
                    IF NEW.supplier_location_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Cannot set supplier_location_id without supplier_id';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.fulfillment_mode IN ('own_stock', 'digital', 'service', 'hybrid') THEN
                    RAISE EXCEPTION 'Fulfillment mode % cannot be supplier-backed', NEW.fulfillment_mode;
                END IF;

                SELECT * INTO v_supplier FROM suppliers WHERE id = NEW.supplier_id;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Supplier % does not exist', NEW.supplier_id;
                END IF;

                IF v_supplier.scope_type = 'platform' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM tenant_supplier_access 
                        WHERE tenant_id = NEW.tenant_id AND supplier_id = v_supplier.id AND is_enabled = true
                    ) THEN
                        RAISE EXCEPTION 'Tenant % is not authorized for Platform Supplier %', 
                            NEW.tenant_id, v_supplier.id;
                    END IF;
                END IF;

                IF v_supplier.scope_type = 'tenant' AND v_supplier.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                    RAISE EXCEPTION 'Fulfillment tenant % does not match Tenant Supplier tenant %', 
                        NEW.tenant_id, v_supplier.tenant_id;
                END IF;

                IF v_supplier.scope_type = 'private_vendor' THEN
                    IF v_supplier.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                        RAISE EXCEPTION 'Fulfillment tenant % does not match Private Supplier tenant %', 
                            NEW.tenant_id, v_supplier.tenant_id;
                    END IF;
                    SELECT * INTO v_seller_order FROM seller_orders WHERE id = NEW.seller_order_id;
                    IF v_seller_order.vendor_id IS DISTINCT FROM v_supplier.vendor_id THEN
                        RAISE EXCEPTION 'Vendor isolation violation: SellerOrder vendor % cannot use Supplier belonging to vendor %',
                            v_seller_order.vendor_id, v_supplier.vendor_id;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_fulfillment_supplier_scope ON order_fulfillments;
            CREATE CONSTRAINT TRIGGER trg_fulfillment_supplier_scope
            AFTER INSERT OR UPDATE ON order_fulfillments
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW EXECUTE FUNCTION check_fulfillment_supplier_scope();
        ");

        // Trigger 3: Supplier Product Variant Scope Enforcement
        DB::unprepared("
            CREATE OR REPLACE FUNCTION check_spv_supplier_catalog_tenancy()
            RETURNS TRIGGER AS $$
            DECLARE
                v_supplier suppliers%ROWTYPE;
            BEGIN
                SELECT * INTO v_supplier FROM suppliers WHERE id = NEW.supplier_id;
                
                IF v_supplier.scope_type IN ('tenant', 'private_vendor') AND v_supplier.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                    RAISE EXCEPTION 'Cross-tenant violation: Supplier tenant % cannot map Product in tenant %', 
                        v_supplier.tenant_id, NEW.tenant_id;
                END IF;
                
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_spv_scope_check ON supplier_product_variants;
            CREATE CONSTRAINT TRIGGER trg_spv_scope_check
            AFTER INSERT OR UPDATE ON supplier_product_variants
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW EXECUTE FUNCTION check_spv_supplier_catalog_tenancy();
        ");

        // Trigger 4: Supplier Invoice Line PO Match
        DB::unprepared("
            CREATE OR REPLACE FUNCTION check_sil_po_match()
            RETURNS TRIGGER AS $$
            DECLARE
                v_invoice supplier_invoices%ROWTYPE;
            BEGIN
                SELECT * INTO v_invoice FROM supplier_invoices WHERE id = NEW.supplier_invoice_id;
                IF v_invoice.purchase_order_id IS DISTINCT FROM NEW.purchase_order_id THEN
                    RAISE EXCEPTION 'Invoice line purchase_order_id % does not match Invoice purchase_order_id %',
                        NEW.purchase_order_id, v_invoice.purchase_order_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_sil_po_match ON supplier_invoice_lines;
            CREATE CONSTRAINT TRIGGER trg_sil_po_match
            AFTER INSERT OR UPDATE ON supplier_invoice_lines
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW EXECUTE FUNCTION check_sil_po_match();
        ");

        // Trigger 5: Supplier Invoice Supplier Match with PO
        DB::unprepared("
            CREATE OR REPLACE FUNCTION check_supplier_invoice_supplier_match()
            RETURNS TRIGGER AS $$
            DECLARE
                v_po purchase_orders%ROWTYPE;
            BEGIN
                SELECT * INTO v_po FROM purchase_orders WHERE id = NEW.purchase_order_id;
                IF v_po.supplier_id IS DISTINCT FROM NEW.supplier_id THEN
                    RAISE EXCEPTION 'Supplier invoice supplier % does not match PurchaseOrder supplier %',
                        NEW.supplier_id, v_po.supplier_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_si_po_supplier_match ON supplier_invoices;
            CREATE CONSTRAINT TRIGGER trg_si_po_supplier_match
            AFTER INSERT OR UPDATE ON supplier_invoices
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW EXECUTE FUNCTION check_supplier_invoice_supplier_match();
        ");

        // Trigger 6: Hybrid Child Invariant
        DB::unprepared("
            CREATE OR REPLACE FUNCTION check_hybrid_fulfillment_child()
            RETURNS TRIGGER AS $$
            DECLARE
                v_parent order_fulfillments%ROWTYPE;
            BEGIN
                IF NEW.parent_fulfillment_id IS NOT NULL THEN
                    SELECT * INTO v_parent FROM order_fulfillments WHERE id = NEW.parent_fulfillment_id;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Parent fulfillment % not found', NEW.parent_fulfillment_id;
                    END IF;

                    IF v_parent.tenant_id IS DISTINCT FROM NEW.tenant_id THEN
                        RAISE EXCEPTION 'Parent fulfillment tenant % does not match child tenant %', 
                            v_parent.tenant_id, NEW.tenant_id;
                    END IF;

                    IF v_parent.seller_order_id IS DISTINCT FROM NEW.seller_order_id THEN
                        RAISE EXCEPTION 'Parent seller order % does not match child seller order %', 
                            v_parent.seller_order_id, NEW.seller_order_id;
                    END IF;

                    IF v_parent.fulfillment_mode != 'hybrid' THEN
                        RAISE EXCEPTION 'Parent fulfillment mode must be hybrid, found %', v_parent.fulfillment_mode;
                    END IF;

                    IF NEW.fulfillment_mode = 'hybrid' THEN
                        RAISE EXCEPTION 'Child fulfillment cannot itself be hybrid';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_hybrid_child ON order_fulfillments;
            CREATE CONSTRAINT TRIGGER trg_hybrid_child
            AFTER INSERT OR UPDATE ON order_fulfillments
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW EXECUTE FUNCTION check_hybrid_fulfillment_child();
        ");
    }

    public function down(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            // Drop triggers
            DB::unprepared('DROP TRIGGER IF EXISTS trg_po_supplier_scope ON purchase_orders');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_fulfillment_supplier_scope ON order_fulfillments');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_spv_scope_check ON supplier_product_variants');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_sil_po_match ON supplier_invoice_lines');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_si_po_supplier_match ON supplier_invoices');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_hybrid_child ON order_fulfillments');

            DB::unprepared('DROP FUNCTION IF EXISTS check_purchase_order_supplier_scope()');
            DB::unprepared('DROP FUNCTION IF EXISTS check_fulfillment_supplier_scope()');
            DB::unprepared('DROP FUNCTION IF EXISTS check_spv_supplier_catalog_tenancy()');
            DB::unprepared('DROP FUNCTION IF EXISTS check_sil_po_match()');
            DB::unprepared('DROP FUNCTION IF EXISTS check_supplier_invoice_supplier_match()');
            DB::unprepared('DROP FUNCTION IF EXISTS check_hybrid_fulfillment_child()');
        }

        // Drop tables in reverse topological order
        Schema::dropIfExists('supplier_return_references');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('seller_returns');
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('order_shipments');
        Schema::dropIfExists('order_fulfillment_items');
        Schema::dropIfExists('order_fulfillments');
        Schema::dropIfExists('supplier_offers');
        Schema::dropIfExists('supplier_product_variants');
        Schema::dropIfExists('supplier_sync_states');
        Schema::dropIfExists('supplier_accounts');
        Schema::dropIfExists('supplier_locations');
        Schema::dropIfExists('tenant_supplier_access');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('seller_order_items');
        Schema::dropIfExists('seller_orders');

        // Remove additive unique constraints and columns
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('uq_products_tenant_id');
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropUnique('uq_payment_transactions_tenant_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropUnique('uq_order_items_tenant_id');
            $table->dropColumn('requires_shipping_snapshot');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('uq_orders_tenant_id');
            $table->dropColumn('commercial_model_snapshot');
        });
    }
};
