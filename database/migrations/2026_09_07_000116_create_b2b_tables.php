<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-20 B2B: Company/CompanyUser (the sole Company-membership source of
 * truth — Owner Delta §1), Company credit exposure as an append-only delta
 * subledger (Owner Delta §1/§3, ADR-0144), and Quote/QuoteLine with
 * per-Cart-line provenance (Owner Delta §3 — never a client-suppliable price
 * override).
 */
return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('tax_id', 100)->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->string('status', 30)->default('pending');
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->string('credit_limit_currency', 3)->nullable();
            $table->bigInteger('credit_limit_minor')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE companies ADD CONSTRAINT chk_companies_status CHECK (status IN ('pending', 'active', 'suspended'))");
        }

        // Owner Delta §1: the SOLE representation of Company membership.
        // No other table (e.g. customer_profiles) duplicates this.
        Schema::create('company_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A User belongs to at most one Company per Tenant (Phase-20 scope).
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['company_id', 'is_active']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE company_users ADD CONSTRAINT chk_company_users_role CHECK (role IN ('owner', 'buyer', 'approver'))");
        }

        Schema::create('company_credit_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->bigInteger('approved_limit_minor');
            $table->timestamps();

            $table->unique(['company_id', 'currency']);
        });

        // Owner Delta §3: a dedicated lock-anchor row, never itself an
        // economic record — mirrors LoyaltyAccountLock exactly.
        Schema::create('company_credit_account_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_credit_account_id')->constrained('company_credit_accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('company_credit_account_id', 'uq_company_credit_lock_account');
        });

        // Owner Delta §1 (economic correction) + §3: pure append-only delta
        // model, no mutable outstanding-balance column anywhere. A
        // reservation increases exposure; release/settlement resolve it back
        // to zero (never both — enforced by the partial unique index below).
        // A refund AFTER settlement never posts here at all (handled by
        // ordinary Payment/accounting semantics only).
        Schema::create('company_credit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_credit_account_id')->constrained('company_credit_accounts')->cascadeOnDelete();
            $table->string('entry_type', 32);
            $table->bigInteger('amount_minor');
            $table->string('source_type', 64);
            $table->string('source_uuid', 64);
            $table->foreignId('reverses_entry_id')->nullable()->constrained('company_credit_entries')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'source_type', 'source_uuid', 'entry_type'], 'uq_company_credit_entries_idem');
            $table->index(['company_credit_account_id', 'created_at']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE company_credit_entries ADD CONSTRAINT chk_company_credit_entry_type CHECK (entry_type IN ('reservation', 'release', 'settlement', 'manual_adjustment_credit', 'manual_adjustment_debit'))");
            // Owner Delta §1: a reservation is resolved EXACTLY ONCE, by
            // either release OR settlement, never both — DB-enforced.
            DB::statement('CREATE UNIQUE INDEX uq_company_credit_entries_one_resolution ON company_credit_entries (reverses_entry_id) WHERE entry_type IN (\'release\', \'settlement\')');
        }

        Schema::create('company_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->timestampTz('due_at');
            $table->string('status', 30)->default('open');
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->unique('order_id');
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE company_invoices ADD CONSTRAINT chk_company_invoices_status CHECK (status IN ('open', 'paid', 'overdue', 'cancelled'))");
        }

        Schema::create('quotes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('status', 30)->default('draft');
            $table->timestampTz('valid_until')->nullable();
            $table->string('currency', 3);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('quoted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
        if ($isPgsql) {
            DB::statement("ALTER TABLE quotes ADD CONSTRAINT chk_quotes_status CHECK (status IN ('draft', 'submitted', 'quoted', 'accepted', 'rejected', 'expired'))");
        }

        Schema::create('quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('quantity', 20, 8);
            $table->bigInteger('negotiated_unit_price_minor')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // Owner Delta §3: negotiated pricing is resolved server-side through
        // a per-Cart-line provenance reference — never a client-suppliable
        // cart_line_id => price map. The client can never set/change this.
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->foreignId('quote_line_id')->nullable()->after('metadata')->constrained('quote_lines')->nullOnDelete();
        });

        // Order-time snapshots (no live FK to a mutable domain row — plain
        // nullable columns only, matching Affiliate's attribution-snapshot
        // precedent so a later Company/Quote edit never alters a historical
        // Order).
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_id')->nullable()->after('user_id');
            $table->unsignedInteger('payment_terms_days')->nullable()->after('company_id');
            $table->unsignedBigInteger('quote_id')->nullable()->after('payment_terms_days');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('quote_line_id')->nullable()->after('customization_metadata_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('quote_line_id');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['company_id', 'payment_terms_days', 'quote_id']);
        });
        Schema::table('cart_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('quote_line_id');
        });
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('company_invoices');
        Schema::dropIfExists('company_credit_entries');
        Schema::dropIfExists('company_credit_account_locks');
        Schema::dropIfExists('company_credit_accounts');
        Schema::dropIfExists('company_users');
        Schema::dropIfExists('companies');
    }
};
