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

        // 1. vendor_plans
        Schema::create('vendor_plans', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 255);
            $table->string('code', 64);
            $table->integer('product_limit')->nullable();
            $table->integer('staff_limit')->default(1);
            $table->boolean('auto_approval')->default(false);
            $table->integer('commission_rate_bps')->nullable();
            $table->bigInteger('fixed_fee_minor')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->boolean('can_manage_suppliers')->default(false);
            $table->boolean('can_dropship')->default(false);
            $table->boolean('has_api_access')->default(false);
            $table->string('ai_tier', 32)->default('none');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_plans_tenant_id');
            $table->unique(['tenant_id', 'code'], 'uq_vendor_plans_tenant_code');
            $table->foreign('tenant_id', 'fk_vendor_plans_tenant')->references('id')->on('tenants')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE vendor_plans ADD CONSTRAINT chk_vendor_plans_commission_rate CHECK (commission_rate_bps >= 0 AND commission_rate_bps <= 10000)');
            DB::statement('ALTER TABLE vendor_plans ADD CONSTRAINT chk_vendor_plans_fixed_fee CHECK (fixed_fee_minor >= 0)');
            DB::statement('ALTER TABLE vendor_plans ADD CONSTRAINT chk_vendor_plans_staff_limit CHECK (staff_limit >= 1)');
        }

        // 2. vendor_plan_prices
        Schema::create('vendor_plan_prices', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_plan_id');
            $table->string('currency', 3);
            $table->bigInteger('monthly_fee_minor');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_plan_prices_tenant_id');
            $table->unique(['tenant_id', 'vendor_plan_id', 'currency'], 'uq_vendor_plan_prices_unique_plan_curr');
            $table->foreign('tenant_id', 'fk_vendor_plan_prices_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_plan_id'], 'fk_vendor_plan_prices_plan')
                ->references(['tenant_id', 'id'])->on('vendor_plans')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE vendor_plan_prices ADD CONSTRAINT chk_vendor_plan_prices_fee CHECK (monthly_fee_minor >= 0)');
        }

        // 3. vendors
        Schema::create('vendors', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('default_store_id')->nullable();
            $table->unsignedBigInteger('vendor_plan_id');
            $table->string('name', 255);
            $table->string('platform_slug', 64)->unique(); // GLOBALLY UNIQUE platform slug
            $table->string('legal_name', 255);
            $table->string('tax_id', 64)->nullable();
            $table->string('email', 255);
            $table->string('phone', 64)->nullable();
            $table->string('operational_status', 32)->default('draft');
            $table->string('verification_status', 32)->default('unverified');
            $table->string('payout_currency', 3)->default('EUR');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('terminated_at')->nullable();
            $table->timestampTz('verification_submitted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('verification_rejected_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendors_tenant_id');
            $table->foreign('tenant_id', 'fk_vendors_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_plan_id'], 'fk_vendors_plan')
                ->references(['tenant_id', 'id'])->on('vendor_plans')->onDelete('restrict');
            $table->foreign('default_store_id', 'fk_vendors_default_store')->references('id')->on('stores')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendors ADD CONSTRAINT chk_vendors_operational_status CHECK (operational_status IN ('draft', 'pending_approval', 'active', 'suspended', 'terminated'))");
            DB::statement("ALTER TABLE vendors ADD CONSTRAINT chk_vendors_verification_status CHECK (verification_status IN ('unverified', 'pending', 'verified', 'rejected', 'needs_review'))");

            // PostgreSQL trigger enforcing vendors.default_store_id tenant isolation
            DB::statement("
                CREATE OR REPLACE FUNCTION trg_verify_vendor_default_store()
                RETURNS TRIGGER AS $$
                DECLARE
                    store_tenant_id BIGINT;
                BEGIN
                    IF NEW.default_store_id IS NOT NULL THEN
                        SELECT tenant_id INTO store_tenant_id FROM stores WHERE id = NEW.default_store_id;
                        IF store_tenant_id IS NULL OR store_tenant_id != NEW.tenant_id THEN
                            RAISE EXCEPTION 'Cross-tenant default store prohibited: store % does not belong to tenant %', NEW.default_store_id, NEW.tenant_id;
                        END IF;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            DB::statement('
                CREATE TRIGGER check_vendors_default_store
                BEFORE INSERT OR UPDATE ON vendors
                FOR EACH ROW EXECUTE FUNCTION trg_verify_vendor_default_store();
            ');
        }

        // 4. vendor_plan_subscriptions
        Schema::create('vendor_plan_subscriptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('vendor_plan_id');
            $table->string('status', 32)->default('pending');
            $table->string('activation_source', 64);
            $table->string('external_subscription_reference', 255)->nullable();
            $table->timestampTz('current_period_starts_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_plan_subscriptions_tenant_id');
            $table->foreign('tenant_id', 'fk_vendor_plan_subs_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_vendor_plan_subs_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_plan_id'], 'fk_vendor_plan_subs_plan')
                ->references(['tenant_id', 'id'])->on('vendor_plans')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendor_plan_subscriptions ADD CONSTRAINT chk_vendor_plan_subs_status CHECK (status IN ('pending', 'active', 'past_due', 'cancelled', 'expired'))");
            DB::statement("ALTER TABLE vendor_plan_subscriptions ADD CONSTRAINT chk_vendor_plan_subs_source CHECK (activation_source IN ('billing_event', 'manual_admin_approval', 'test_fake'))");
        }

        // 5. vendor_verifications
        Schema::create('vendor_verifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->string('provider_name', 64)->default('manual');
            $table->string('external_reference_id', 255)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('rejection_reason_code', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('submitted_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_verifications_tenant_id');
            $table->foreign('tenant_id', 'fk_vendor_verifications_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_vendor_verifications_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendor_verifications ADD CONSTRAINT chk_vendor_verifications_status CHECK (status IN ('pending', 'verified', 'rejected', 'needs_review'))");
        }

        // 6. vendor_store_participations
        Schema::create('vendor_store_participations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('store_id');
            $table->boolean('is_enabled')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_store_parts_tenant_id');
            $table->unique(['tenant_id', 'vendor_id', 'store_id'], 'uq_vendor_store_parts_unique');
            $table->foreign('tenant_id', 'fk_vendor_store_parts_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_vendor_store_parts_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
            $table->foreign('store_id', 'fk_vendor_store_parts_store')->references('id')->on('stores')->onDelete('restrict');
        });

        // 7. vendor_users (Staff/RBAC)
        Schema::create('vendor_users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_users_tenant_id');
            $table->unique(['tenant_id', 'vendor_id', 'user_id'], 'uq_vendor_users_unique_membership');
            $table->foreign('tenant_id', 'fk_vendor_users_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_vendor_users_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
            $table->foreign('user_id', 'fk_vendor_users_user')->references('id')->on('users')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendor_users ADD CONSTRAINT chk_vendor_users_role CHECK (role IN ('owner', 'manager', 'staff'))");
            // Exactly-one active owner partial unique index:
            DB::statement("CREATE UNIQUE INDEX uq_vendor_single_owner ON vendor_users (tenant_id, vendor_id) WHERE role = 'owner' AND is_active = true");
        }

        // 8. vendor_invitations
        Schema::create('vendor_invitations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->string('email', 255);
            $table->string('role', 32);
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_invitations_tenant_id');
            $table->foreign('tenant_id', 'fk_vendor_invitations_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_vendor_invitations_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
            $table->foreign('accepted_by_user_id', 'fk_vendor_invitations_user')->references('id')->on('users')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendor_invitations ADD CONSTRAINT chk_vendor_invitations_role CHECK (role IN ('manager', 'staff'))");
            DB::statement("ALTER TABLE vendor_invitations ADD CONSTRAINT chk_vendor_invitations_status CHECK (status IN ('pending', 'accepted', 'revoked', 'expired'))");
        }

        // 9. vendor_storefront_profiles
        Schema::create('vendor_storefront_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->string('display_name', 255);
            $table->string('logo_url', 500)->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->text('bio')->nullable();
            $table->jsonb('policies')->nullable();
            $table->jsonb('contact_info')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_storefront_profiles_tenant_id');
            $table->unique(['tenant_id', 'vendor_id'], 'uq_vendor_storefront_profiles_vendor');
            $table->foreign('tenant_id', 'fk_vendor_profiles_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_vendor_profiles_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
        });

        // 10. vendor_domains
        Schema::create('vendor_domains', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->string('domain', 255)->unique(); // GLOBALLY UNIQUE normalized canonical ASCII hostname
            $table->string('verification_token', 64);
            $table->string('status', 32)->default('requested');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_domains_tenant_id');
            $table->foreign('tenant_id', 'fk_vendor_domains_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_vendor_domains_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendor_domains ADD CONSTRAINT chk_vendor_domains_status CHECK (status IN ('requested', 'verification_pending', 'verified', 'active', 'revoked'))");
        }

        // 11. vendor_listings (Commercial offers of canonical catalog products)
        Schema::create('vendor_listings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('vendor_sku', 64);
            $table->string('status', 32)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_listings_tenant_id');
            $table->foreign('tenant_id', 'fk_vendor_listings_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_vendor_listings_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
            $table->foreign('product_id', 'fk_vendor_listings_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('product_variant_id', 'fk_vendor_listings_variant')->references('id')->on('product_variants')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendor_listings ADD CONSTRAINT chk_vendor_listings_status CHECK (status IN ('active', 'inactive'))");
            // Partial unique indexes resolving PostgreSQL NULL behavior:
            DB::statement('CREATE UNIQUE INDEX uq_vendor_listings_product ON vendor_listings (tenant_id, vendor_id, product_id) WHERE product_variant_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX uq_vendor_listings_variant ON vendor_listings (tenant_id, vendor_id, product_id, product_variant_id) WHERE product_variant_id IS NOT NULL');

            // PostgreSQL trigger enforcing cross-tenant catalog boundaries and variant-product integrity:
            DB::statement("
                CREATE OR REPLACE FUNCTION trg_verify_vendor_listing_catalog()
                RETURNS TRIGGER AS $$
                DECLARE
                    prod_tenant_id BIGINT;
                    var_tenant_id BIGINT;
                    var_product_id BIGINT;
                BEGIN
                    SELECT tenant_id INTO prod_tenant_id FROM products WHERE id = NEW.product_id;
                    IF prod_tenant_id IS NULL OR prod_tenant_id != NEW.tenant_id THEN
                        RAISE EXCEPTION 'Cross-tenant catalog reference prohibited: product % does not belong to tenant %', NEW.product_id, NEW.tenant_id;
                    END IF;

                    IF NEW.product_variant_id IS NOT NULL THEN
                        SELECT p.tenant_id, pv.product_id INTO var_tenant_id, var_product_id
                        FROM product_variants pv
                        JOIN products p ON p.id = pv.product_id
                        WHERE pv.id = NEW.product_variant_id;

                        IF var_tenant_id IS NULL OR var_tenant_id != NEW.tenant_id THEN
                            RAISE EXCEPTION 'Cross-tenant catalog variant reference prohibited: variant % does not belong to tenant %', NEW.product_variant_id, NEW.tenant_id;
                        END IF;
                        IF var_product_id != NEW.product_id THEN
                            RAISE EXCEPTION 'Catalog variant mismatch prohibited: variant % does not belong to product %', NEW.product_variant_id, NEW.product_id;
                        END IF;
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            DB::statement('
                CREATE TRIGGER check_vendor_listings_catalog
                BEFORE INSERT OR UPDATE ON vendor_listings
                FOR EACH ROW EXECUTE FUNCTION trg_verify_vendor_listing_catalog();
            ');
        }

        // 12. vendor_listing_store_availabilities
        Schema::create('vendor_listing_store_availabilities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_listing_id');
            $table->unsignedBigInteger('store_id');
            $table->boolean('is_enabled')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_listing_store_avails_tenant_id');
            $table->unique(['tenant_id', 'vendor_listing_id', 'store_id'], 'uq_listing_store_avails_unique');
            $table->foreign('tenant_id', 'fk_listing_store_avails_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_listing_id'], 'fk_listing_store_avails_listing')
                ->references(['tenant_id', 'id'])->on('vendor_listings')->onDelete('restrict');
            $table->foreign('store_id', 'fk_listing_store_avails_store')->references('id')->on('stores')->onDelete('restrict');
        });

        if ($isPgsql) {
            // PostgreSQL triggers enforcing cross-tenant store relationships fail closed:
            DB::statement("
                CREATE OR REPLACE FUNCTION trg_verify_store_tenant()
                RETURNS TRIGGER AS $$
                BEGIN
                    IF (SELECT tenant_id FROM stores WHERE id = NEW.store_id) != NEW.tenant_id THEN
                        RAISE EXCEPTION 'Cross-tenant store relationship is prohibited: store % does not belong to tenant %', NEW.store_id, NEW.tenant_id;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            DB::statement('
                CREATE TRIGGER check_vendor_store_participations_tenant
                BEFORE INSERT OR UPDATE ON vendor_store_participations
                FOR EACH ROW EXECUTE FUNCTION trg_verify_store_tenant();
            ');
            DB::statement('
                CREATE TRIGGER check_vendor_listing_store_availabilities_tenant
                BEFORE INSERT OR UPDATE ON vendor_listing_store_availabilities
                FOR EACH ROW EXECUTE FUNCTION trg_verify_store_tenant();
            ');
        }

        // 13. vendor_commission_rules (Without effective_from / effective_to per Phase-11 closure)
        Schema::create('vendor_commission_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->integer('rate_basis_points');
            $table->bigInteger('fixed_fee_minor')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_commission_rules_tenant_id');
            $table->foreign('tenant_id', 'fk_commission_rules_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_commission_rules_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
            $table->foreign('category_id', 'fk_commission_rules_category')->references('id')->on('categories')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE vendor_commission_rules ADD CONSTRAINT chk_commission_rules_rate CHECK (rate_basis_points >= 0 AND rate_basis_points <= 10000)');
            DB::statement('ALTER TABLE vendor_commission_rules ADD CONSTRAINT chk_commission_rules_fixed_fee CHECK (fixed_fee_minor >= 0)');
            // Non-overlapping commission scope partial unique indexes:
            DB::statement('CREATE UNIQUE INDEX uq_commission_tenant_default ON vendor_commission_rules (tenant_id, currency) WHERE vendor_id IS NULL AND category_id IS NULL AND is_active = true');
            DB::statement('CREATE UNIQUE INDEX uq_commission_vendor_global ON vendor_commission_rules (tenant_id, vendor_id, currency) WHERE vendor_id IS NOT NULL AND category_id IS NULL AND is_active = true');
            DB::statement('CREATE UNIQUE INDEX uq_commission_vendor_category ON vendor_commission_rules (tenant_id, vendor_id, category_id, currency) WHERE vendor_id IS NOT NULL AND category_id IS NOT NULL AND is_active = true');
        }

        // 14. vendor_payable_entries (Directional, append-only operational subledger)
        Schema::create('vendor_payable_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('entry_type', 32);
            $table->string('source_type', 64);
            $table->string('source_uuid', 64);
            $table->string('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('commission_amount_minor')->default(0);
            $table->bigInteger('net_amount_minor');
            $table->string('availability_status', 32)->default('pending');
            $table->timestampTz('available_at')->nullable();
            $table->string('held_reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_vendor_payable_entries_tenant_id');
            $table->unique(['tenant_id', 'source_type', 'source_uuid', 'entry_type'], 'uq_payable_entries_unique_movement');
            $table->foreign('tenant_id', 'fk_payable_entries_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_payable_entries_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
            $table->foreign('order_item_id', 'fk_payable_entries_order_item')->references('id')->on('order_items')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE vendor_payable_entries ADD CONSTRAINT chk_payable_entry_type CHECK (entry_type IN ('earning', 'refund_adjustment', 'manual_adjustment_credit', 'manual_adjustment_debit', 'payout_disbursement'))");
            DB::statement("ALTER TABLE vendor_payable_entries ADD CONSTRAINT chk_payable_avail_status CHECK (availability_status IN ('pending', 'available', 'held'))");
            // Positive financial movements & disbursement invariants:
            DB::statement('ALTER TABLE vendor_payable_entries ADD CONSTRAINT chk_payable_amounts_positive CHECK (amount_minor > 0 AND commission_amount_minor >= 0 AND net_amount_minor >= 0)');
            DB::statement("ALTER TABLE vendor_payable_entries ADD CONSTRAINT chk_payable_disbursement_invariants CHECK ((entry_type != 'payout_disbursement') OR (commission_amount_minor = 0 AND net_amount_minor = amount_minor))");

            // PostgreSQL trigger enforcing immutable economic fields and strictly preventing DELETE:
            DB::statement("
                CREATE OR REPLACE FUNCTION trg_protect_vendor_payable_entries()
                RETURNS TRIGGER AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Deleting rows from vendor_payable_entries is strictly prohibited';
                    END IF;

                    IF TG_OP = 'UPDATE' THEN
                        IF NEW.tenant_id != OLD.tenant_id OR
                           NEW.vendor_id != OLD.vendor_id OR
                           NEW.order_item_id IS DISTINCT FROM OLD.order_item_id OR
                           NEW.entry_type != OLD.entry_type OR
                           NEW.source_type != OLD.source_type OR
                           NEW.source_uuid != OLD.source_uuid OR
                           NEW.currency != OLD.currency OR
                           NEW.amount_minor != OLD.amount_minor OR
                           NEW.commission_amount_minor != OLD.commission_amount_minor OR
                           NEW.net_amount_minor != OLD.net_amount_minor
                        THEN
                            RAISE EXCEPTION 'Economic fields of vendor_payable_entries are immutable and cannot be updated';
                        END IF;
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            ");
            DB::statement('
                CREATE TRIGGER check_vendor_payable_entries_protection
                BEFORE UPDATE OR DELETE ON vendor_payable_entries
                FOR EACH ROW EXECUTE FUNCTION trg_protect_vendor_payable_entries();
            ');
        }

        // 15. payout_batches
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 255);
            $table->string('status', 32)->default('draft');
            $table->string('currency', 3)->default('EUR');
            $table->bigInteger('total_amount_minor')->default(0);
            $table->integer('item_count')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_payout_batches_tenant_id');
            $table->foreign('tenant_id', 'fk_payout_batches_tenant')->references('id')->on('tenants')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE payout_batches ADD CONSTRAINT chk_payout_batches_status CHECK (status IN ('draft', 'processing', 'completed', 'partially_failed'))");
        }

        // 16. payout_requests
        Schema::create('payout_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('payout_batch_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('requested');
            $table->jsonb('destination_details')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_payout_requests_tenant_id');
            $table->foreign('tenant_id', 'fk_payout_requests_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_id'], 'fk_payout_requests_vendor')
                ->references(['tenant_id', 'id'])->on('vendors')->onDelete('restrict');
            $table->foreign(['tenant_id', 'payout_batch_id'], 'fk_payout_requests_batch')
                ->references(['tenant_id', 'id'])->on('payout_batches')->onDelete('restrict');
            $table->foreign('approved_by_user_id', 'fk_payout_requests_approver')->references('id')->on('users')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE payout_requests ADD CONSTRAINT chk_payout_requests_status CHECK (status IN ('requested', 'approved', 'processing', 'paid', 'failed', 'cancelled'))");
            DB::statement('ALTER TABLE payout_requests ADD CONSTRAINT chk_payout_requests_amount CHECK (amount_minor > 0)');
        }

        // 17. payout_request_allocations (Explicit reservation junction)
        Schema::create('payout_request_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('payout_request_id');
            $table->unsignedBigInteger('vendor_payable_entry_id');
            $table->bigInteger('allocated_amount_minor');
            $table->string('status', 32)->default('reserved');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'uq_payout_allocs_tenant_id');
            $table->unique(['tenant_id', 'payout_request_id', 'vendor_payable_entry_id'], 'uq_payout_allocs_request_entry');
            $table->foreign('tenant_id', 'fk_payout_allocs_tenant')->references('id')->on('tenants')->onDelete('restrict');
            $table->foreign(['tenant_id', 'payout_request_id'], 'fk_payout_allocs_request')
                ->references(['tenant_id', 'id'])->on('payout_requests')->onDelete('restrict');
            $table->foreign(['tenant_id', 'vendor_payable_entry_id'], 'fk_payout_allocs_entry')
                ->references(['tenant_id', 'id'])->on('vendor_payable_entries')->onDelete('restrict');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE payout_request_allocations ADD CONSTRAINT chk_payout_allocs_status CHECK (status IN ('reserved', 'consumed', 'released'))");
            DB::statement('ALTER TABLE payout_request_allocations ADD CONSTRAINT chk_payout_allocs_amount CHECK (allocated_amount_minor > 0)');
        }
    }

    public function down(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('DROP TRIGGER IF EXISTS check_vendor_payable_entries_protection ON vendor_payable_entries');
            DB::statement('DROP FUNCTION IF EXISTS trg_protect_vendor_payable_entries()');
            DB::statement('DROP TRIGGER IF EXISTS check_vendor_listing_store_availabilities_tenant ON vendor_listing_store_availabilities');
            DB::statement('DROP TRIGGER IF EXISTS check_vendor_store_participations_tenant ON vendor_store_participations');
            DB::statement('DROP FUNCTION IF EXISTS trg_verify_store_tenant()');
            DB::statement('DROP TRIGGER IF EXISTS check_vendor_listings_catalog ON vendor_listings');
            DB::statement('DROP FUNCTION IF EXISTS trg_verify_vendor_listing_catalog()');
            DB::statement('DROP TRIGGER IF EXISTS check_vendors_default_store ON vendors');
            DB::statement('DROP FUNCTION IF EXISTS trg_verify_vendor_default_store()');
        }

        Schema::dropIfExists('payout_request_allocations');
        Schema::dropIfExists('payout_requests');
        Schema::dropIfExists('payout_batches');
        Schema::dropIfExists('vendor_payable_entries');
        Schema::dropIfExists('vendor_commission_rules');
        Schema::dropIfExists('vendor_listing_store_availabilities');
        Schema::dropIfExists('vendor_listings');
        Schema::dropIfExists('vendor_domains');
        Schema::dropIfExists('vendor_storefront_profiles');
        Schema::dropIfExists('vendor_invitations');
        Schema::dropIfExists('vendor_users');
        Schema::dropIfExists('vendor_store_participations');
        Schema::dropIfExists('vendor_verifications');
        Schema::dropIfExists('vendor_plan_subscriptions');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('vendor_plan_prices');
        Schema::dropIfExists('vendor_plans');
    }
};
