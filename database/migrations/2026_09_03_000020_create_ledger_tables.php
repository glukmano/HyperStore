<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ledger Accounts
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('restrict');
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('type', 32); // asset, liability, equity, revenue, expense
            $table->string('normal_balance', 16); // debit, credit
            $table->string('role', 64)->nullable(); // semantic system role, e.g. payment_clearing
            $table->string('currency', 3)->nullable(); // null = multi-currency; string = restricted
            $table->boolean('is_system')->default(false);
            $table->string('status', 32)->default('active'); // active, archived
            $table->text('description')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->unique(['tenant_id', 'id'], 'uq_ledger_accounts_tenant_id');
            $table->unique(['tenant_id', 'code'], 'uq_ledger_accounts_tenant_code');
        });

        // 2. Journal Entries (Append-Only)
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('restrict');
            $table->string('source_module', 64);
            $table->string('source_type', 64);
            $table->string('source_uuid', 255);
            $table->string('posting_type', 64); // capture, refund, reversal
            $table->string('currency', 3);
            $table->unsignedBigInteger('reverses_journal_entry_id')->nullable();
            $table->string('description', 500);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('effective_at');
            $table->timestampTz('posted_at');
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'id'], 'uq_journal_entries_tenant_id');
            $table->unique(
                ['tenant_id', 'source_module', 'source_type', 'source_uuid', 'posting_type'],
                'uq_journal_entries_source'
            );

            $table->foreign(['tenant_id', 'reverses_journal_entry_id'], 'fk_journal_entries_reversal_tenant')
                ->references(['tenant_id', 'id'])
                ->on('journal_entries')
                ->onDelete('restrict');
        });

        // 3. Journal Lines (Append-Only)
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('restrict');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('ledger_account_id');
            $table->string('direction', 16); // debit, credit
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('description', 255)->nullable();
            $table->timestampTz('created_at');

            $table->foreign(['tenant_id', 'journal_entry_id'], 'fk_journal_lines_tenant_entry')
                ->references(['tenant_id', 'id'])
                ->on('journal_entries')
                ->onDelete('restrict');

            $table->foreign(['tenant_id', 'ledger_account_id'], 'fk_journal_lines_tenant_account')
                ->references(['tenant_id', 'id'])
                ->on('ledger_accounts')
                ->onDelete('restrict');

            $table->index(['tenant_id', 'ledger_account_id', 'currency'], 'idx_journal_lines_tenant_account');
        });

        // 4. PostgreSQL Specific Constraints, Partial Indexes and Immutability Triggers
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT chk_ledger_accounts_type CHECK (type IN ('asset', 'liability', 'equity', 'revenue', 'expense'))");
            DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT chk_ledger_accounts_normal_balance CHECK (normal_balance IN ('debit', 'credit'))");
            DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT chk_ledger_accounts_status CHECK (status IN ('active', 'archived'))");
            DB::statement('CREATE UNIQUE INDEX uq_ledger_accounts_tenant_role ON ledger_accounts (tenant_id, role) WHERE role IS NOT NULL');

            DB::statement('CREATE UNIQUE INDEX uq_journal_reversals ON journal_entries (tenant_id, reverses_journal_entry_id) WHERE reverses_journal_entry_id IS NOT NULL');

            DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_direction CHECK (direction IN ('debit', 'credit'))");
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_amount_positive CHECK (amount_minor > 0)');

            // Immutability trigger function and triggers
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_posted_journal_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'Financial accounting records are immutable. UPDATE and DELETE are prohibited.';
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_journal_entries_immutable
                BEFORE UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_journal_lines_immutable
                BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_journal_lines_immutable ON journal_lines');
            DB::statement('DROP TRIGGER IF EXISTS trg_journal_entries_immutable ON journal_entries');
            DB::statement('DROP FUNCTION IF EXISTS prevent_posted_journal_mutation()');
        }

        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
